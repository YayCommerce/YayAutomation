import { defineStep } from '../../core/defineStep';
import { OrderStatusChangedForm } from './Form';
import { defaults, normalize, schema, type OrderStatusChangedConfig } from './schema';

export const orderStatusChangedStep = defineStep<OrderStatusChangedConfig>({
  id: 'order_status_changed',
  kind: 'trigger',
  schema,
  defaults,
  normalize,
  Form: OrderStatusChangedForm,
});
