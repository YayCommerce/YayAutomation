import type { StepId } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

import type { DataFieldType, DataItemMeta, PluginMeta, StepMeta } from '@/types/localize';

/**
 * Placeholder engine meta for steps and data fields whose PHP side isn't built yet, so their forms,
 * summaries and templates work in the admin. PHP meta always wins: an entry stops being "coming
 * soon" as soon as PHP sends it. Once PHP sends everything here, delete this file and read
 * `window.yayAutomationMeta.automation` directly again.
 *
 * What PHP has to match: the YayAutomation plugin in `Workflow\Extend\Plugins` and Condition's `plugin`, each pending block's `schema.ts` (its `sanitize()` / `validate()`), the
 * meta and fields below, boolean operators with no value (`needsValue()`), and action `provides`
 * in `WorkflowValidator` (`availableData()`).
 */

/** YayAutomation's own steps (Condition, later Wait) are grouped under it, like Shopify Flow's. */
const plugins: PluginMeta[] = [
  {
    id: 'yayautomation',
    label: 'YayAutomation',
    description: __('Workflow logic: conditions and waits.', 'yay-automation'),
    active: true,
  },
];

/** Meta PHP sends for a block but without these keys. */
const blockExtras: Partial<Record<StepId, Partial<StepMeta>>> = {
  condition: { plugin: 'yayautomation' },
};

const steps: Partial<Record<StepId, StepMeta>> = {
  send_email: { kind: 'action', plugin: 'woocommerce', category: 'email', requires: [] },
  generate_coupon: { kind: 'action', plugin: 'woocommerce', category: 'coupon', requires: [], provides: ['coupon'] },
  wait: { kind: 'action', plugin: 'yayautomation', category: 'flow', requires: [] },
};

const data: DataItemMeta[] = [
  {
    id: 'order',
    label: __('Order', 'yay-automation'),
    description: '',
    fields: [
      // Cross-sells / upsells of the order's products, minus what the order already has.
      {
        key: 'order.has_cross_sells',
        label: __('Order has cross-sell products', 'yay-automation'),
        type: 'boolean',
        conditional: true,
      },
      {
        key: 'order.has_upsells',
        label: __('Order has upsell products', 'yay-automation'),
        type: 'boolean',
        conditional: true,
      },
      // Shown as a product grid (image, name, price, link).
      {
        key: 'order.cross_sell_products',
        label: __('Cross-sell products', 'yay-automation'),
        type: 'string',
        conditional: false,
      },
      {
        key: 'order.upsell_products',
        label: __('Upsell products', 'yay-automation'),
        type: 'string',
        conditional: false,
      },
    ],
  },
  {
    id: 'customer',
    label: __('Customer', 'yay-automation'),
    description: '',
    fields: [
      {
        key: 'customer.paid_order_count',
        label: __('Customer paid order count', 'yay-automation'),
        type: 'number',
        conditional: true,
      },
      {
        key: 'customer.unsubscribed',
        label: __('Customer unsubscribed', 'yay-automation'),
        type: 'boolean',
        conditional: true,
      },
    ],
  },
  {
    id: 'coupon',
    label: __('Coupon', 'yay-automation'),
    description: __('The coupon made by a Generate coupon step above.', 'yay-automation'),
    fields: [
      { key: 'coupon.code', label: __('Coupon code', 'yay-automation'), type: 'string', conditional: false },
      // Shown with its unit: "10%", "$5.00" or "Free shipping".
      { key: 'coupon.amount', label: __('Coupon amount', 'yay-automation'), type: 'string', conditional: false },
      {
        key: 'coupon.expiry_date',
        label: __('Coupon expiry date', 'yay-automation'),
        type: 'date',
        conditional: false,
      },
      {
        key: 'coupon.description',
        label: __('Coupon description', 'yay-automation'),
        type: 'string',
        conditional: false,
      },
    ],
  },
];

const operators: Partial<Record<DataFieldType, string[]>> = {
  boolean: ['is_true', 'is_false'],
};

const php = window.yayAutomationMeta.automation;

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

/** `window.yayAutomationMeta.automation` with the stubs filled in under it. */
export const automationMeta = {
  ...php,
  plugins: [...php.plugins, ...plugins.filter((plugin) => !php.plugins.some((p) => p.id === plugin.id))],
  steps: Object.fromEntries(
    Object.entries({ ...steps, ...php.steps }).map(([id, meta]) => [id, { ...blockExtras[id as StepId], ...meta }]),
  ) as Record<StepId, StepMeta>,
  data: mergeData(),
  operators: { ...operators, ...php.operators } as Record<DataFieldType, string[]>,
};

/** The block's PHP side isn't built yet: shown, but can't be picked or installed. */
export const isPendingStep = (id: StepId) => pendingSteps.has(id);

/** The field's PHP side isn't built yet: shown, but can't be picked. */
export const isPendingField = (key: string) => pendingFields.has(key);

export const comingSoonLabel = __('Coming soon', 'yay-automation');
