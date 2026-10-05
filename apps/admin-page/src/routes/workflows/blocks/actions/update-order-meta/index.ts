import { __, sprintf } from '@wordpress/i18n';

import { defineBlock, PLACEHOLDER } from '../../core/defineBlock';
import { UpdateOrderMetaForm } from './Form';
import { defaults, schema, type UpdateOrderMetaConfig } from './schema';

export const updateOrderMetaBlock = defineBlock<UpdateOrderMetaConfig>({
  id: 'update_order_meta',
  kind: 'action',
  label: __('Update order custom field', 'yay-automation'),
  description: __('Set a custom field (meta) on the order.', 'yay-automation'),
  schema,
  defaults,
  summary: ({ key, value }) =>
    /* translators: 1: custom field name, 2: value */
    sprintf(__('Set the order field %1$s to %2$s', 'yay-automation'), key.trim() || PLACEHOLDER, value || '""'),
  Form: UpdateOrderMetaForm,
});
