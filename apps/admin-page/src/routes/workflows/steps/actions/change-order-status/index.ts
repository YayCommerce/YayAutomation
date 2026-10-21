import { defineStep } from '../../core/defineStep';

/** Form and checks come from includes/Workflow/Actions/ChangeOrderStatus.php `fields()`. */
export const changeOrderStatusStep = defineStep({ id: 'change_order_status', kind: 'action' });
