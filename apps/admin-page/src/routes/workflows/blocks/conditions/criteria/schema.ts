import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import { fieldByKey, needsValue, operatorsFor } from '../../core/data';

/**
 * Mirrors includes/Engine/Blocks/Conditions/Criteria.php: criteria joined by AND (`all`) or OR (`any`).
 * Values are kept as typed and cast by the field type when compared.
 */
export type Rule = { field: string; operator: string; value: string };
export type CriteriaConfig = { match: 'all' | 'any'; rules: Rule[] };

export const emptyRule = (): Rule => ({ field: '', operator: '', value: '' });

/** Why one criteria can't run, or null. Same checks, order and messages as PHP `Criteria::rule_error()`. */
export function ruleError({ field: key, operator, value }: Rule): string | null {
  const field = fieldByKey(key);
  if (!field?.conditional) return __('Choose a variable for each criteria.', 'yay-automation');
  if (!operatorsFor(field.type).includes(operator))
    return __('Choose a comparison for each criteria.', 'yay-automation');
  if (!needsValue(operator)) return null;

  // PHP trims values on save.
  if (value.trim() === '') return __('Enter a value for each criteria.', 'yay-automation');
  if (field.type === 'number' && !/^\s*[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?\s*$/i.test(value)) {
    return __('Enter a number.', 'yay-automation');
  }
  if (field.type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(value)) return __('Enter a date.', 'yay-automation');
  // Without the plugin that provides the options there's nothing to check against.
  if (field.options?.length && !field.options.some((option) => option.value === value)) {
    return __('Choose one of the listed values.', 'yay-automation');
  }
  return null;
}

export const schema = v.pipe(
  v.object({
    match: v.picklist(['all', 'any']),
    rules: v.array(v.object({ field: v.string(), operator: v.string(), value: v.string() })),
  }),
  v.rawCheck(({ dataset, addIssue }) => {
    if (!dataset.typed) return;
    const { rules } = dataset.value;
    const error = rules.length
      ? rules.map(ruleError).find(Boolean)
      : __('Add at least one criteria.', 'yay-automation');
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
      }))
    : [],
});
