import { __, sprintf } from '@wordpress/i18n';

import { defineStep, value } from '../../core/defineStep';
import { statusLabel } from '../../triggers/order-status-changed/schema';
import { ChangeOrderStatusForm } from './Form';
import { defaults, schema, type ChangeOrderStatusConfig } from './schema';

export const changeOrderStatusStep = defineStep<ChangeOrderStatusConfig>({
  id: 'change_order_status',
  kind: 'action',
  label: __('Change order status', 'yay-automation'),
  description: __('Move the order to another status.', 'yay-automation'),
  schema,
  defaults,
  /* translators: %s: order status */
  summary: ({ status }) =>
    sprintf(__('Change the order status to %s', 'yay-automation'), value(status ? statusLabel(status) : '')),
  Form: ChangeOrderStatusForm,
});
