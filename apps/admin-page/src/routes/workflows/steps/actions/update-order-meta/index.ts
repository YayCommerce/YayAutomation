import { defineStep } from '../../core/defineStep';

/** Form and checks come from includes/Workflow/Actions/UpdateOrderMeta.php `fields()`. */
export const updateOrderMetaStep = defineStep({ id: 'update_order_meta', kind: 'action' });
