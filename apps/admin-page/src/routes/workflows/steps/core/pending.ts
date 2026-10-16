import type { StepId } from '@yaymatik/types';
import { __ } from '@wordpress/i18n';

import type { DataFieldType, DataItemMeta, StepMeta } from '@/types/localize';

/**
 * Placeholder engine meta for steps and data fields whose PHP side isn't built yet, so their forms,
 * summaries and templates work in the admin. PHP meta always wins: an entry stops being "coming
 * soon" as soon as PHP sends it. Once PHP sends everything here, delete this file and read
 * `window.yayMatikMeta.automation` directly again.
 *
 * What PHP has to match: each pending step's `schema.ts` (its `sanitize()` / `validate()`), the
 * meta and fields below, boolean operators with no value (`needsValue()`), and action `provides`
 * in `Validator` (`availableData()`).
 */

const steps: Partial<Record<StepId, StepMeta>> = {
  send_email: { kind: 'action', plugin: 'woocommerce', category: 'email', requires: [] },
  wait: { kind: 'action', plugin: 'yaymatik', category: 'flow', requires: [] },
};

const data: DataItemMeta[] = [
  {
    id: 'order',
    label: __('Order', 'yaymatik'),
    description: '',
    fields: [
      // Cross-sells / upsells of the order's products, minus what the order already has.
      {
        key: 'order.has_cross_sells',
        label: __('Order has cross-sell products', 'yaymatik'),
        type: 'boolean',
        conditional: true,
      },
      {
        key: 'order.has_upsells',
        label: __('Order has upsell products', 'yaymatik'),
        type: 'boolean',
        conditional: true,
      },
      // Shown as a product grid (image, name, price, link).
      {
        key: 'order.cross_sell_products',
        label: __('Cross-sell products', 'yaymatik'),
        type: 'string',
        conditional: false,
      },
      {
        key: 'order.upsell_products',
        label: __('Upsell products', 'yaymatik'),
        type: 'string',
        conditional: false,
      },
    ],
  },
  {
    id: 'customer',
    label: __('Customer', 'yaymatik'),
    description: '',
    fields: [
      {
        key: 'customer.paid_order_count',
        label: __('Customer paid order count', 'yaymatik'),
        type: 'number',
        conditional: true,
      },
      {
        key: 'customer.unsubscribed',
        label: __('Customer unsubscribed', 'yaymatik'),
        type: 'boolean',
        conditional: true,
      },
    ],
  },
];

const operators: Partial<Record<DataFieldType, string[]>> = {
  boolean: ['is_true', 'is_false'],
};

const php = window.yayMatikMeta.automation;

const pendingSteps = new Set(Object.keys(steps).filter((id) => !php.steps[id as StepId]) as StepId[]);

const phpFields = new Set(php.data.flatMap((item) => item.fields.map((field) => field.key)));
const pendingFields = new Set(
  data.flatMap((item) => item.fields.map((field) => field.key)).filter((key) => !phpFields.has(key)),
);

/** Stub fields PHP lacks, added to PHP's items (or as new items after them). */
function mergeData(): DataItemMeta[] {
  const merged = php.data.map((item) => {
    const stub = data.find((s) => s.id === item.id);
    return stub ? { ...item, fields: [...item.fields, ...stub.fields.filter((f) => pendingFields.has(f.key))] } : item;
  });
  const newItems = data.filter((stub) => !php.data.some((item) => item.id === stub.id));
  return [...merged, ...newItems];
}

/** `window.yayMatikMeta.automation` with the stubs filled in under it. */
export const automationMeta = {
  ...php,
  steps: { ...steps, ...php.steps } as Record<StepId, StepMeta>,
  data: mergeData(),
  operators: { ...operators, ...php.operators } as Record<DataFieldType, string[]>,
};

/** The step's PHP side isn't built yet: shown, but can't be picked or installed. */
export const isPendingStep = (id: StepId) => pendingSteps.has(id);

/** The field's PHP side isn't built yet: shown, but can't be picked. */
export const isPendingField = (key: string) => pendingFields.has(key);

export const comingSoonLabel = __('Coming soon', 'yaymatik');
