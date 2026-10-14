import { defineStep } from '../../core/defineStep';
import { ChangeOrderStatusForm } from './Form';
import { defaults, schema, type ChangeOrderStatusConfig } from './schema';

export const changeOrderStatusStep = defineStep<ChangeOrderStatusConfig>({
  id: 'change_order_status',
  kind: 'action',
  schema,
  defaults,
  Form: ChangeOrderStatusForm,
});
