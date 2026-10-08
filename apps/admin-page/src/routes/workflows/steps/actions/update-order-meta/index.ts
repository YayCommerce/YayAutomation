import { __, sprintf } from '@wordpress/i18n';

import { defineStep, value } from '../../core/defineStep';
import { UpdateOrderMetaForm } from './Form';
import { defaults, schema, type UpdateOrderMetaConfig } from './schema';

export const updateOrderMetaStep = defineStep<UpdateOrderMetaConfig>({
  id: 'update_order_meta',
  kind: 'action',
  label: __('Update order custom field', 'yay-automation'),
  description: __('Set a custom field (meta) on the order.', 'yay-automation'),
  schema,
  defaults,
  summary: ({ key, value: fieldValue }) =>
    /* translators: 1: custom field name, 2: value */
    sprintf(__('Set the order field %1$s to %2$s', 'yay-automation'), value(key), value(fieldValue)),
  Form: UpdateOrderMetaForm,
});
