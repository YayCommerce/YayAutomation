import { __, sprintf } from '@wordpress/i18n';

import type { StepFieldMeta } from '@/types/localize';
import { pathLabel } from './data';
import { PLACEHOLDER, value } from './marks';
import { parseTemplate } from './template';
import type { Config } from './types';

/** Writes one summary placeholder for a step: returns marked text (`value()`, `PLACEHOLDER`); blank → `PLACEHOLDER`. */
export type SummaryValues<TConfig> = Partial<Record<Extract<keyof TConfig, string>, (config: TConfig) => string>>;

/** "A", "A or B", "A, B or C". */
export function orList(values: string[]) {
  if (values.length < 2) return values.join('');
  /* translators: 1: comma-separated list, 2: the last item */
  return sprintf(__('%1$s or %2$s', 'yay-automation'), values.slice(0, -1).join(', '), values[values.length - 1]);
}

/**
 * Text with each merge tag as the name of what it reads: "Order {{ order.number }}" → "Order Order
 * number". A malformed tag, a step output or a path nothing defines stays as typed.
 */
export const variableNames = (text: string) =>
  parseTemplate(text)
    .map((token) => {
      if (token.type === 'text') return token.text;
      return (token.error === null && pathLabel(token.path)) || token.raw;
    })
    .join('');

const optionLabel = (field: StepFieldMeta, raw: unknown) =>
  field.options?.find((option) => option.value === raw)?.label ?? String(raw);

/** A field's value as plain card text: option labels, lists joined, whitespace collapsed, merge tags by name. */
function fieldText(field: StepFieldMeta, raw: unknown): string {
  const names = (text: string) => (field.variables ? variableNames(text) : text);
  if (raw === undefined || raw === null) return '';
  switch (field.type) {
    case 'select':
      return raw === '' ? '' : optionLabel(field, raw);
    case 'multiselect':
      return Array.isArray(raw) ? orList(raw.map((item) => optionLabel(field, item))) : '';
    case 'emails':
      return names(
        String(raw)
          .split(',')
          .map((email) => email.trim())
          .filter(Boolean)
          .join(', '),
      );
    default:
      return typeof raw === 'object' ? '' : names(String(raw).replace(/\s+/g, ' ').trim());
  }
}

/**
 * Card text from PHP's summary template (`Step::summary()`, e.g. "Change the order status to
 * %status%"): each `%key%` becomes that field's value in bold, or the italic "Not set" when blank.
 * A blank multiselect means "any", so it shows its placeholder (e.g. "Any status") instead.
 * `values` writes keys a field can't describe on its own (a condition's rules).
 */
export function renderSummary<TConfig extends Config>(
  template: string,
  fields: StepFieldMeta[],
  config: TConfig,
  values: SummaryValues<TConfig> = {},
) {
  return template.replace(/%([a-z_]+)%/g, (whole, key: string) => {
    const custom = values[key as Extract<keyof TConfig, string>];
    if (custom) return custom(config) || PLACEHOLDER;
    const field = fields.find((f) => f.key === key);
    if (!field) return whole;
    const text = fieldText(field, config[key]);
    return value(text || (field.type === 'multiselect' ? (field.placeholder ?? '') : ''));
  });
}
