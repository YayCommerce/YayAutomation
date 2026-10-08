import { __, sprintf } from '@wordpress/i18n';

import type { DataFieldType, DataItemMeta } from '@/types/localize';
import { automationMeta, isPendingField } from './pending';

/**
 * Data items and their fields, from PHP `DataRegistry` (labels, types and options live there).
 * Fields are both condition variables and `{{ item.field }}` merge tags.
 */

export type DataField = DataItemMeta['fields'][number];

const meta = automationMeta;

export const dataItems = meta.data;

/** Items every workflow has, whatever the trigger supplies. */
export const alwaysAvailable = meta.always;

const fields = new Map(dataItems.flatMap((item) => item.fields.map((field) => [field.key, field] as const)));

export const fieldByKey = (key: string): DataField | undefined => fields.get(key);

/** `order.total` → `order`. */
export const itemOf = (key: string) => key.split('.')[0];

export const itemLabel = (id: string) => dataItems.find((item) => item.id === id)?.label ?? id;

/** Operator ids for a field type, from PHP `Operators::BY_TYPE`. */
export const operatorsFor = (type: DataFieldType) => meta.operators[type];

/**
 * Operators that compare against nothing. Mirrors PHP `Operators::needs_value()`, which doesn't
 * have the boolean ones yet (see `pending.ts`).
 */
export const needsValue = (operator: string) => !['is_empty', 'is_not_empty', 'is_true', 'is_false'].includes(operator);

export const operatorLabels: Record<string, string> = {
  equal: __('Equal to', 'yay-automation'),
  not_equal: __('Not equal to', 'yay-automation'),
  greater_than: __('Greater than', 'yay-automation'),
  greater_or_equal: __('Greater than or equal to', 'yay-automation'),
  less_than: __('Less than', 'yay-automation'),
  less_or_equal: __('Less than or equal to', 'yay-automation'),
  contains: __('Contains', 'yay-automation'),
  not_contains: __('Does not contain', 'yay-automation'),
  starts_with: __('Starts with', 'yay-automation'),
  ends_with: __('Ends with', 'yay-automation'),
  is_empty: __('Is empty', 'yay-automation'),
  is_not_empty: __('Is not empty', 'yay-automation'),
  is: __('Is', 'yay-automation'),
  is_not: __('Is not', 'yay-automation'),
  before: __('Before', 'yay-automation'),
  after: __('After', 'yay-automation'),
  is_true: __('Is true', 'yay-automation'),
  is_false: __('Is false', 'yay-automation'),
};

/** Same pattern as PHP `Variables::PATTERN`. Spaces inside the braces are optional. */
const VARIABLE_PATTERN = /\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/g;

/** Full keys of the merge tags in a text, without duplicates. */
export const variableKeys = (text: string) => [...new Set([...text.matchAll(VARIABLE_PATTERN)].map((m) => m[1]))];

/** Merge tag for a key, as inserted by "Add variable". */
export const variableTag = (key: string) => `{{ ${key} }}`;

/**
 * Error for the first merge tag no data item defines, or null. Mirrors PHP `Action::unknown_variable()`,
 * so fields PHP doesn't have yet count as unknown too.
 */
export function unknownVariable(...texts: string[]) {
  return firstUnknown(texts, false);
}

/** `unknownVariable()` for a block still coming soon: fields PHP doesn't have yet are fine there. */
export function unknownVariableAllowingPending(...texts: string[]) {
  return firstUnknown(texts, true);
}

function firstUnknown(texts: string[], allowPending: boolean) {
  for (const text of texts) {
    const unknown = variableKeys(text).find((key) => !fieldByKey(key) || (!allowPending && isPendingField(key)));
    if (unknown) {
      /* translators: %s: merge tag, e.g. {{ order.foo }} */
      return sprintf(__('Unknown variable: %s', 'yay-automation'), variableTag(unknown));
    }
  }
  return null;
}
