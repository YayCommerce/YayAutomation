import { __, _n, sprintf } from '@wordpress/i18n';

import { fieldByKey, isRelative, itemOf, needsValue, operatorLabels, type DataField } from '../../core/data';
import { defineStep, PLACEHOLDER, value } from '../../core/defineStep';
import { CriteriaForm } from './Form';
import { defaults, normalize, schema, type CriteriaConfig, type Rule } from './schema';

/** A card has room for this many; beyond it, the first and a count. */
const MAX_SUMMARY_ITEMS = 3;

const optionLabel = (field: DataField | undefined, value: string) =>
  field?.options?.find((option) => option.value === value)?.label ?? value;

/** What a rule compares with, as text. Search lists hold ids, and a summary never asks the server for their names. */
function comparedText(field: DataField | undefined, { operator, value: compared, valueType, unit }: Rule) {
  if (Array.isArray(compared)) {
    if (!compared.length) return '';
    return field?.options?.length
      ? compared.map((item) => optionLabel(field, item)).join(', ')
      : /* translators: %d: how many values a rule lists */
        sprintf(__('%d selected', 'yaymatik'), compared.length);
  }
  if (isRelative(operator)) {
    // Being typed: "0" isn't an amount yet.
    if (!/^[1-9]\d*$/.test(compared)) return '';
    const amount = Number(compared);
    return sprintf(
      unit === 'hours'
        ? /* translators: %d: number of hours */ _n('%d hour', '%d hours', amount, 'yaymatik')
        : /* translators: %d: number of days */ _n('%d day', '%d days', amount, 'yaymatik'),
      amount,
    );
  }
  return valueType === 'variable' ? (fieldByKey(compared)?.label ?? '') : optionLabel(field, compared);
}

/** "Order total is greater than or equal to 500"; placeholders for the parts still missing. */
function describe(rule: Rule) {
  const { field: key, operator } = rule;
  const field = fieldByKey(key);
  const shown = needsValue(operator) ? comparedText(field, rule) : '';
  // "Custom field vip is equal to yes".
  const name = field?.type === 'meta' ? `${field.label} ${rule.key || PLACEHOLDER}` : field?.label;
  /* translators: 1: variable, 2: comparison, e.g. "is greater than", 3: value */
  return sprintf(
    __('%1$s %2$s %3$s', 'yaymatik'),
    name ?? PLACEHOLDER,
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
      [rule.field, rule.valueType === 'variable' && needsValue(rule.operator) ? String(rule.value) : '']
        .filter(Boolean)
        .map(itemOf),
    ),
  // The rules on one line, joined by "and" / "or"; blank until a rule has its variable.
  summaryValues: {
    rules: ({ match, rules }) => {
      const joiner = match === 'any' ? __('or', 'yaymatik') : __('and', 'yaymatik');
      const chosen = rules.filter((rule) => rule.field).map(describe);
      if (chosen.length <= MAX_SUMMARY_ITEMS) return chosen.join(` ${joiner} `);
      const more =
        match === 'any'
          ? /* translators: %d: how many more rules */ __('or %d more', 'yaymatik')
          : /* translators: %d: how many more rules */ __('and %d more', 'yaymatik');
      return `${chosen[0]} ${sprintf(more, chosen.length - 1)}`;
    },
  },
  Form: CriteriaForm,
});
