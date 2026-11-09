import * as v from 'valibot';
import { sprintf } from '@wordpress/i18n';

import type { StepFieldMeta } from '@/types/localize';
import { messages } from '../../../model/messages';
import { tagError } from '../data';
import type { Config } from '../types';
import { isWpEmail, splitRecipients } from './email';

/** PHP `Field::visible()`: shown unless `showIf` names another field's value it doesn't have. */
export function isFieldVisible(field: StepFieldMeta, config: Config) {
  if (!field.showIf) return true;
  const wanted: unknown[] = Array.isArray(field.showIf.value) ? field.showIf.value : [field.showIf.value];
  return wanted.includes(config[field.showIf.field] ?? null);
}

/** Stored values of a fresh step: each field's default (a notice stores nothing). */
export const defaultsFromFields = (fields: StepFieldMeta[]): Config =>
  Object.fromEntries(
    fields
      .filter((field) => field.type !== 'notice')
      .map((field) => [field.key, structuredClone(field.default ?? null)]),
  );

/** PHP `Field::is_blank()` on unsanitized input: PHP trims text before it checks. */
function isBlank(field: StepFieldMeta, value: unknown) {
  if (field.type === 'toggle') return false;
  if (field.type === 'number_unit') return numberUnitValue(value) === null;
  // Rules with no variable chosen yet don't count.
  if (field.type === 'rules') {
    type Started = { field?: unknown; rules?: unknown } | null;
    const started = (rule: Started) => !!rule?.field;
    return (
      !Array.isArray(value) ||
      !value.some((rule: Started) =>
        Array.isArray(rule?.rules) ? rule.rules.some((inner: Started) => started(inner)) : started(rule),
      )
    );
  }
  if (field.type === 'html') return isBlankHtml(String(value ?? ''));
  if (typeof value === 'string') return value.trim() === '';
  return value === null || value === undefined || (Array.isArray(value) && value.length === 0);
}

/** PHP `Field::is_blank()` for `html`: an emptied rich editor leaves `<p>&nbsp;</p>`; an image alone still counts. */
export const isBlankHtml = (html: string) =>
  !/<img\b/i.test(html) &&
  html
    .replace(/<[^>]*>/g, '')
    .replace(/&nbsp;/g, ' ')
    .trim() === '';

const numberUnitValue = (value: unknown) => {
  const number = (value as { value?: unknown } | null)?.value;
  return typeof number === 'number' ? number : null;
};

/** PHP `Field::range_error()` once the number isn't blank. */
function rangeError(field: StepFieldMeta, number: number) {
  if (field.min !== undefined && number < field.min) return sprintf(messages.numberMin, String(field.min));
  if (field.max !== undefined && number > field.max) return sprintf(messages.numberMax, String(field.max));
  return null;
}

/** Every value is in the list. An empty list (e.g. no WooCommerce) accepts anything, like PHP. */
const known = (field: StepFieldMeta, values: unknown[]) =>
  !field.options?.length || values.every((value) => field.options!.some((option) => option.value === String(value)));

/**
 * Why one field's value can't be used, or null. Mirrors PHP `Field::validate()`, same messages.
 * `stepLabel` fills the required message's `%s`.
 */
function fieldError(field: StepFieldMeta, config: Config, stepLabel: string): string | null {
  if (field.type === 'notice' || !isFieldVisible(field, config)) return null;
  const value = config[field.key];
  if (isBlank(field, value)) {
    if (!field.required) return null;
    // The message holds one %s; the cast tells sprintf's types to expect one argument.
    return field.requiredMessage ? sprintf(field.requiredMessage as '%s', stepLabel) : messages.fieldRequired;
  }
  switch (field.type) {
    case 'emails': {
      const invalid = splitRecipients(String(value)).find(
        (email) => !(field.variables && email.includes('{{')) && !isWpEmail(email),
      );
      return invalid === undefined ? null : sprintf(messages.invalidEmail, invalid);
    }
    case 'select':
      return known(field, [value]) ? null : messages.chooseOption;
    case 'multiselect':
      return Array.isArray(value) && known(field, value) ? null : messages.chooseOptions;
    case 'number':
      return rangeError(field, Number(value));
    case 'number_unit':
      return rangeError(field, numberUnitValue(value)!);
  }
  return null;
}

const pathTo = (config: Config, key: string): [v.ObjectPathItem] => [
  { type: 'object', origin: 'value', input: config, key, value: config[key] },
];

/**
 * A step's valibot schema, built from its catalog fields: each field's own rules, then the merge
 * tags in the fields that accept them (checked only once the rules pass, like PHP checks tags after
 * `validate()`). Each issue carries its field's path, so the form shows it under that field, and
 * the first issue is the one PHP reports.
 */
export function schemaFromFields(fields: StepFieldMeta[], stepLabel: string): v.GenericSchema<Config> {
  return v.pipe(
    v.record(v.string(), v.unknown()),
    // One check, not two in the pipe: valibot runs every check in a pipe even after one fails.
    v.rawCheck(({ dataset, addIssue }) => {
      if (!dataset.typed) return;
      const config = dataset.value;
      let failed = false;
      for (const field of fields) {
        const message = fieldError(field, config, stepLabel);
        if (message) addIssue({ message, path: pathTo(config, field.key) });
        failed ||= !!message;
      }
      if (failed) return;
      for (const field of fields) {
        const text = config[field.key];
        const message = field.variables && typeof text === 'string' ? tagError([text]) : null;
        if (message) addIssue({ message, path: pathTo(config, field.key) });
      }
    }),
  );
}
