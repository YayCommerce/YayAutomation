import { __, sprintf } from '@wordpress/i18n';

import { fieldByKey, itemOf, needsValue } from '../../core/data';
import { defineStep, PLACEHOLDER } from '../../core/defineStep';
import { CriteriaForm } from './Form';
import { defaults, normalize, schema, type CriteriaConfig, type Rule } from './schema';

/** How each operator reads in a sentence. */
const phrases: Record<string, string> = {
  equal: __('is equal to', 'yay-automation'),
  not_equal: __('is not equal to', 'yay-automation'),
  greater_than: __('is greater than', 'yay-automation'),
  greater_or_equal: __('is greater than or equal to', 'yay-automation'),
  less_than: __('is less than', 'yay-automation'),
  less_or_equal: __('is less than or equal to', 'yay-automation'),
  contains: __('contains', 'yay-automation'),
  not_contains: __('does not contain', 'yay-automation'),
  starts_with: __('starts with', 'yay-automation'),
  ends_with: __('ends with', 'yay-automation'),
  is_empty: __('is empty', 'yay-automation'),
  is_not_empty: __('is not empty', 'yay-automation'),
  is: __('is', 'yay-automation'),
  is_not: __('is not', 'yay-automation'),
  before: __('is before', 'yay-automation'),
  after: __('is after', 'yay-automation'),
  is_true: __('is true', 'yay-automation'),
  is_false: __('is false', 'yay-automation'),
};

/** "Order total is greater than or equal to 500"; placeholders for the parts still missing. */
function describe({ field: key, operator, value, valueType }: Rule) {
  const field = fieldByKey(key);
  const shown = !needsValue(operator)
    ? ''
    : valueType === 'variable'
      ? (fieldByKey(value)?.label ?? '')
      : (field?.options?.find((option) => option.value === value)?.label ?? value);
  /* translators: 1: variable, 2: comparison, e.g. "is greater than", 3: value */
  return sprintf(
    __('%1$s %2$s %3$s', 'yay-automation'),
    field?.label ?? PLACEHOLDER,
    phrases[operator] ?? PLACEHOLDER,
    shown || (needsValue(operator) ? PLACEHOLDER : ''),
  ).trim();
}

export const criteriaStep = defineStep<CriteriaConfig>({
  id: 'condition',
  kind: 'condition',
  label: __('Condition', 'yay-automation'),
  description: __('Take different actions based on the conditions you set', 'yay-automation'),
  schema,
  defaults,
  normalize,
  // The variable on the left, and the one on the right when comparing two.
  uses: ({ rules }) =>
    rules.flatMap((rule) =>
      [rule.field, rule.valueType === 'variable' && needsValue(rule.operator) ? rule.value : '']
        .filter(Boolean)
        .map(itemOf),
    ),
  // One line per criteria with the AND / OR between them; the card keeps the line breaks.
  summary: ({ match, rules }) => {
    const started = rules.filter((rule) => rule.field);
    if (!started.length) return __('Select condition', 'yay-automation');
    const joiner = match === 'any' ? __('OR', 'yay-automation') : __('AND', 'yay-automation');
    return started.map(describe).join(`\n${joiner}\n`);
  },
  Form: CriteriaForm,
});
