import { __, sprintf } from '@wordpress/i18n';

import { defineBlock } from '../../core/defineBlock';
import { OrderStatusChangedForm } from './Form';
import { defaults, normalize, schema, statusLabel, type OrderStatusChangedConfig } from './schema';

/** "A", "A or B", "A, B or C". */
function orList(values: string[]) {
  if (values.length < 2) return values.join('');
  /* translators: 1: comma-separated list of statuses, 2: the last status */
  return sprintf(__('%1$s or %2$s', 'yay-automation'), values.slice(0, -1).join(', '), values[values.length - 1]);
}

export const orderStatusChangedBlock = defineBlock<OrderStatusChangedConfig>({
  id: 'order_status_changed',
  kind: 'trigger',
  label: __('Order status changed', 'yay-automation'),
  description: __(
    'Triggers after an order changes status occurs. Set the workflow to run on certain status changes with the trigger options',
    'yay-automation',
  ),
  schema,
  defaults,
  normalize,
  summary: ({ from, to }) => {
    const target = to.length ? orList(to.map(statusLabel)) : __('any status', 'yay-automation');
    return from.length
      ? /* translators: 1: previous order status(es), 2: new order status(es) */
        sprintf(__('Order status changes from %1$s to %2$s', 'yay-automation'), orList(from.map(statusLabel)), target)
      : /* translators: %s: new order status(es) */
        sprintf(__('Order status changes to %s', 'yay-automation'), target);
  },
  Form: OrderStatusChangedForm,
});
