import { defineStep } from '../../core/defineStep';
import { UpdateOrderMetaForm } from './Form';
import { defaults, schema, type UpdateOrderMetaConfig } from './schema';

export const updateOrderMetaStep = defineStep<UpdateOrderMetaConfig>({
  id: 'update_order_meta',
  kind: 'action',
  schema,
  defaults,
  Form: UpdateOrderMetaForm,
});
