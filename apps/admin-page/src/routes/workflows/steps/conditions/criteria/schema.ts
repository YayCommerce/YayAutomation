import * as v from 'valibot';
import { __, sprintf } from '@wordpress/i18n';

import { messages } from '../../../model/messages';
import {
  fieldByKey,
  isRelative,
  needsValue,
  operatorLabels,
  operatorsFor,
  takesList,
  type DataField,
} from '../../core/data';

/**
 * Mirrors includes/Workflow/Conditions/Criteria.php: criteria joined by AND (`all`) or OR (`any`).
 * `valueType: 'value'` compares with `value` as typed (cast by the field type); `'variable'` compares
 * with another field, whose key `value` holds. `value` is a list for list comparisons, a whole number
 * with `unit` for relative dates.
 */
export type RuleUnit = 'hours' | 'days';
export type Rule = {
  field: string;
  operator: string;
  value: string | string[];
  valueType: 'value' | 'variable';
  unit?: RuleUnit;
  /** A meta field's custom field. */
  key?: string;
};
/** Rules joined by their own all / any, inside a condition's rules only. */
export type Group = { match: 'all' | 'any'; rules: Rule[] };
export type CriteriaItem = Rule | Group;
export type CriteriaConfig = { match: 'all' | 'any'; rules: CriteriaItem[] };

/** How items read between each other, under `all` and `any`. */
export const joinerText = { all: __('and', 'yaymatik'), any: __('or', 'yaymatik') };

export const isGroup = (item: CriteriaItem): item is Group => 'rules' in item;

/** The rules themselves, those inside groups in their place. */
export const flatRules = (items: CriteriaItem[]): Rule[] =>
  items.flatMap((item) => (isGroup(item) ? item.rules : [item]));

export const emptyGroup = (): Group => ({ match: 'all', rules: [emptyRule()] });

/** A group's error of its own (no rule chosen yet), and each of its rules'. */
export type GroupErrors = { own: string | null; rules: (string | null)[] };

export const emptyRule = (): Rule => ({ field: '', operator: '', value: '', valueType: 'value' });

const shapeOf = (operator: string) => (takesList(operator) ? 'list' : isRelative(operator) ? 'relative' : 'single');

const blankValue = (operator: string) => (takesList(operator) ? [] : '');

/** The rule with another comparison; a value of another shape is cleared: a date doesn't become an amount. */
export function withOperator(rule: Rule, operator: string): Rule {
  if (shapeOf(rule.operator) === shapeOf(operator)) return { ...rule, operator };
  const next: Rule = { ...rule, operator, value: blankValue(operator), valueType: 'value' };
  if (isRelative(operator)) next.unit = 'days';
  else delete next.unit;
  return next;
}

/** Whether a rule's value still means something on another field of the same type. */
function valueFits(rule: Rule, from: DataField | undefined, to: DataField, trigger: string | null) {
  if (rule.valueType === 'variable') {
    const other = fieldByKey(String(rule.value), trigger);
    return !!other && comparableWith(to)(other);
  }
  // A product id means nothing as a category.
  if (from?.search !== to.search) return false;
  if (!to.options?.length) return true;
  const known = (item: string) => to.options?.some((option) => option.value === item);
  return Array.isArray(rule.value) ? rule.value.every(known) : known(rule.value);
}

/** The rule on another field: the same type keeps the comparison and any value that fits; another type starts over. */
export function ruleForField(rule: Rule, key: string, trigger: string | null): Rule {
  const from = fieldByKey(rule.field, trigger);
  const to = fieldByKey(key, trigger)!;
  const same = to.type === from?.type;
  const base: Rule = { field: key, operator: rule.operator, value: rule.value, valueType: rule.valueType };
  if (rule.unit) base.unit = rule.unit;
  // Order and customer custom fields are different stores.
  if (to.type === 'meta') base.key = rule.field === key ? (rule.key ?? '') : '';
  const next = withOperator(base, same ? rule.operator : operatorsFor(to.type)[0]);
  if (same && valueFits(rule, from, to, trigger)) return next;
  return { ...next, value: blankValue(next.operator), valueType: 'value' };
}

/** Types whose value may be another variable. Option lists keep their own options. */
export const VARIABLE_VALUE_TYPES: DataField['type'][] = ['number', 'string', 'date'];

/** Fields a rule on `field` may compare with: same type, usable in conditions. */
export const comparableWith = (field: DataField) => (other: DataField) =>
  other.conditional && other.type === field.type && VARIABLE_VALUE_TYPES.includes(field.type);

/** Whether a rule reads a trigger fact, as its variable or as the one it compares with. Mirrors PHP `Criteria::reads_fact()`. */
const readsFact = (rule: Rule) =>
  rule.field.startsWith('trigger.') || (rule.valueType === 'variable' && String(rule.value).startsWith('trigger.'));

/** Why one criteria can't run, or null. Same checks, order and messages as PHP `Criteria::rule_error()`. */
function ruleError(rule: Rule, trigger: string | null | undefined): string | null {
  // No trigger known here (the step schema): a rule on a fact is the workflow's check, `factIssue()`.
  if (trigger === undefined && readsFact(rule)) return null;
  const { field: fieldKey, operator, value, valueType, key } = rule;
  const field = fieldByKey(fieldKey, trigger);
  if (!field?.conditional) return messages.ruleVariable;
  if (!operatorsFor(field.type).includes(operator)) return messages.ruleComparison;
  // Which keys are blocked is the server's to say, `ruleMetaKey` after a save: they depend on the store.
  if (field.type === 'meta' && !key?.trim()) return messages.ruleKeyRequired;
  if (!needsValue(operator)) return null;

  if (takesList(operator)) {
    if (!Array.isArray(value) || !value.length) return messages.ruleListRequired;
    const known = (item: string) => field.options?.some((option) => option.value === item);
    return field.options?.length && !value.every(known) ? messages.ruleOption : null;
  }
  const text = typeof value === 'string' ? value : '';
  if (isRelative(operator)) return /^[1-9]\d*$/.test(text.trim()) ? null : messages.ruleAmount;

  if (valueType === 'variable') {
    const other = fieldByKey(text, trigger);
    return other && comparableWith(field)(other) ? null : messages.ruleCompareWith;
  }

  // PHP trims values on save.
  if (text.trim() === '') {
    const required = sprintf(messages.ruleValueRequired, field.label);
    // Text can be checked for blank instead.
    return field.type === 'string'
      ? `${required} ${sprintf(messages.ruleBlankHint, operatorLabels.is_empty)}`
      : required;
  }
  if (field.type === 'number' && !/^\s*[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?\s*$/i.test(text)) {
    return messages.ruleNumber;
  }
  if (field.type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(text)) return messages.ruleDate;
  // Without the plugin that provides the options there's nothing to check against.
  if (field.options?.length && !field.options.some((option) => option.value === text)) {
    return messages.ruleOption;
  }
  return null;
}

/** No criteria has a variable yet. */
const criteriaRequired = messages.rulesRequired;

/** A group's errors: an empty one needs a rule. */
const groupErrors = ({ rules }: Group, trigger: string | null | undefined): GroupErrors =>
  rules.some((rule) => rule.field)
    ? { own: null, rules: rules.map((rule) => ruleError(rule, trigger)) }
    : { own: messages.groupRulesRequired, rules: rules.map(() => null) };

/**
 * The error of each item, in order (null when fine). With no variable chosen anywhere, the first one
 * carries `rulesRequired`. Same order as PHP `Criteria::validate()`.
 */
export function criteriaErrors(items: CriteriaItem[], trigger?: string | null): (string | GroupErrors | null)[] {
  if (flatRules(items).some((rule) => rule.field)) {
    return items.map((item) => (isGroup(item) ? groupErrors(item, trigger) : ruleError(item, trigger)));
  }
  return items.map((item, i) => {
    const own = i === 0 ? criteriaRequired : null;
    return isGroup(item) ? { own, rules: item.rules.map(() => null) } : own;
  });
}

/** The condition's first error, or null. No criteria at all also needs one. */
function criteriaError(items: CriteriaItem[]) {
  if (!items.length) return criteriaRequired;
  for (const error of criteriaErrors(items)) {
    if (typeof error === 'string') return error;
    const inner = error && (error.own ?? error.rules.find(Boolean));
    if (inner) return inner;
  }
  return null;
}

const ruleSchema = v.object({
  field: v.string(),
  operator: v.string(),
  value: v.union([v.string(), v.array(v.string())]),
  valueType: v.picklist(['value', 'variable']),
  unit: v.optional(v.picklist(['hours', 'days'])),
  key: v.optional(v.string()),
});

export const schema = v.pipe(
  v.object({
    match: v.picklist(['all', 'any']),
    rules: v.array(v.union([ruleSchema, v.object({ match: v.picklist(['all', 'any']), rules: v.array(ruleSchema) })])),
  }),
  v.rawCheck(({ dataset, addIssue }) => {
    if (!dataset.typed) return;
    const error = criteriaError(dataset.value.rules);
    if (error) addIssue({ message: error });
  }),
);

/** The first broken rule on a trigger fact, against the workflow's trigger. Mirrors PHP `Criteria::context_error()`. */
export function factIssue({ rules }: CriteriaConfig, trigger: string) {
  const message = flatRules(rules)
    .filter(readsFact)
    .map((rule) => ruleError(rule, trigger))
    .find(Boolean);
  return message ? { message } : null;
}

/** One empty criteria to start, joined by AND once a second one is added. */
export const defaults = (): CriteriaConfig => ({ match: 'all', rules: [emptyRule()] });

export const normalize = (config: Record<string, unknown>): CriteriaConfig => ({
  match: config.match === 'any' ? 'any' : 'all',
  rules: Array.isArray(config.rules)
    ? config.rules.map((item: Partial<Rule> & { match?: unknown; rules?: unknown }) =>
        Array.isArray(item?.rules)
          ? {
              match: item.match === 'any' ? 'any' : 'all',
              // One level deep, as PHP `Criteria::sanitize_group()`: a group inside a group is dropped.
              rules: item.rules
                .filter((inner) => inner && typeof inner === 'object' && !Array.isArray(inner.rules))
                .map(normalizeRule),
            }
          : normalizeRule(item),
      )
    : [],
});

/** One rule as PHP `Criteria::sanitize_rule()` stores it; a plain rule has exactly its four keys. */
function normalizeRule(rule: Partial<Rule>): Rule {
  const field = String(rule?.field ?? '');
  const meta = fieldByKey(field)?.type === 'meta';
  const operator = String(rule?.operator ?? '');
  const raw = rule?.value;
  const text = Array.isArray(raw) ? '' : String(raw ?? '');
  const items = Array.isArray(raw) ? raw : [text];
  const list = [...new Set(items.map((item) => (typeof item === 'string' ? item.trim() : '')).filter(Boolean))];
  const fixed = meta || takesList(operator) || isRelative(operator);
  return {
    field,
    operator,
    value: takesList(operator) ? list : text,
    valueType: !fixed && rule?.valueType === 'variable' ? 'variable' : 'value',
    ...(isRelative(operator) ? { unit: rule?.unit === 'hours' ? 'hours' : 'days' } : {}),
    ...(meta ? { key: String(rule?.key ?? '').trim() } : {}),
  };
}
