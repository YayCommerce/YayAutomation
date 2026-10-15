import { __, sprintf } from '@wordpress/i18n';

import { fieldByKey, itemOf, needsValue, operatorLabels } from '../../core/data';
import { defineStep, PLACEHOLDER, value } from '../../core/defineStep';
import { CriteriaForm } from './Form';
import { defaults, normalize, schema, type CriteriaConfig, type Rule } from './schema';

/** "Order total is greater than or equal to 500"; placeholders for the parts still missing. */
function describe({ field: key, operator, value: compared, valueType }: Rule) {
  const field = fieldByKey(key);
  const shown = !needsValue(operator)
    ? ''
    : valueType === 'variable'
      ? (fieldByKey(compared)?.label ?? '')
      : (field?.options?.find((option) => option.value === compared)?.label ?? compared);
  /* translators: 1: variable, 2: comparison, e.g. "is greater than", 3: value */
  return sprintf(
    __('%1$s %2$s %3$s', 'yaymatik'),
    field?.label ?? PLACEHOLDER,
    operatorLabels[operator] ?? PLACEHOLDER,
    shown ? value(shown) : needsValue(operator) ? PLACEHOLDER : '',
  ).trim();
}

export const criteriaStep = defineStep<CriteriaConfig>({
  id: 'condition',
  kind: 'condition',
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
  // The rules on one line, joined by "and" / "or"; blank until a rule has its variable.
  summaryValues: {
    rules: ({ match, rules }) => {
      const joiner = match === 'any' ? __('or', 'yaymatik') : __('and', 'yaymatik');
      return rules
        .filter((rule) => rule.field)
        .map(describe)
        .join(` ${joiner} `);
    },
  },
  Form: CriteriaForm,
});
