import * as v from 'valibot';
import { sprintf } from '@wordpress/i18n';

import { messages } from '../../../model/messages';
import { fieldByKey, needsValue, operatorLabels, operatorsFor, type DataField } from '../../core/data';

/**
 * Mirrors includes/Workflow/Conditions/Criteria.php: criteria joined by AND (`all`) or OR (`any`).
 * `valueType: 'value'` compares with `value` as typed (cast by the field type); `'variable'` compares
 * with another field, whose key `value` holds.
 */
export type Rule = { field: string; operator: string; value: string; valueType: 'value' | 'variable' };
export type CriteriaConfig = { match: 'all' | 'any'; rules: Rule[] };

export const emptyRule = (): Rule => ({ field: '', operator: '', value: '', valueType: 'value' });

/** Types whose value may be another variable. Option lists keep their own options. */
export const VARIABLE_VALUE_TYPES: DataField['type'][] = ['number', 'string', 'date'];

/** Fields a rule on `field` may compare with: same type, usable in conditions. */
export const comparableWith = (field: DataField) => (other: DataField) =>
  other.conditional && other.type === field.type && VARIABLE_VALUE_TYPES.includes(field.type);

/** Why one criteria can't run, or null. Same checks, order and messages as PHP `Criteria::rule_error()`. */
function ruleError({ field: key, operator, value, valueType }: Rule): string | null {
  const field = fieldByKey(key);
  if (!field?.conditional) return messages.ruleVariable;
  if (!operatorsFor(field.type).includes(operator)) return messages.ruleComparison;
  if (!needsValue(operator)) return null;

  if (valueType === 'variable') {
    const other = fieldByKey(value);
    return other && comparableWith(field)(other) ? null : messages.ruleCompareWith;
  }

  // PHP trims values on save.
  if (value.trim() === '') {
    const required = sprintf(messages.ruleValueRequired, field.label);
    // Text can be checked for blank instead.
    return field.type === 'string'
      ? `${required} ${sprintf(messages.ruleBlankHint, operatorLabels.is_empty)}`
      : required;
  }
  if (field.type === 'number' && !/^\s*[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?\s*$/i.test(value)) {
    return messages.ruleNumber;
  }
  if (field.type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(value)) return messages.ruleDate;
  // Without the plugin that provides the options there's nothing to check against.
  if (field.options?.length && !field.options.some((option) => option.value === value)) {
    return messages.ruleOption;
  }
  return null;
}

/** No criteria has a variable yet. */
export const criteriaRequired = messages.rulesRequired;

/**
 * The error of each criteria, in order (null when fine). With no variable chosen anywhere, the
 * first one carries `rulesRequired`. Same order as PHP `Criteria::validate()`.
 */
export const criteriaErrors = (rules: Rule[]): (string | null)[] =>
  rules.some((rule) => rule.field) ? rules.map(ruleError) : rules.map((_, i) => (i === 0 ? criteriaRequired : null));

/** The condition's first error, or null. No criteria at all also needs one. */
const criteriaError = (rules: Rule[]) =>
  rules.length ? (criteriaErrors(rules).find(Boolean) ?? null) : criteriaRequired;

export const schema = v.pipe(
  v.object({
    match: v.picklist(['all', 'any']),
    rules: v.array(
      v.object({
        field: v.string(),
        operator: v.string(),
        value: v.string(),
        valueType: v.picklist(['value', 'variable']),
      }),
    ),
  }),
  v.rawCheck(({ dataset, addIssue }) => {
    if (!dataset.typed) return;
    const error = criteriaError(dataset.value.rules);
    if (error) addIssue({ message: error });
  }),
);

/** One empty criteria to start, joined by AND once a second one is added. */
export const defaults = (): CriteriaConfig => ({ match: 'all', rules: [emptyRule()] });

export const normalize = (config: Record<string, unknown>): CriteriaConfig => ({
  match: config.match === 'any' ? 'any' : 'all',
  rules: Array.isArray(config.rules)
    ? config.rules.map((rule: Partial<Rule>) => ({
        field: String(rule?.field ?? ''),
        operator: String(rule?.operator ?? ''),
        value: String(rule?.value ?? ''),
        valueType: rule?.valueType === 'variable' ? 'variable' : 'value',
      }))
    : [],
});
