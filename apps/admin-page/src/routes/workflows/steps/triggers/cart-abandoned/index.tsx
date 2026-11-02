import { defineStep } from '../../core/defineStep';
import { CartTriggerNotes } from '../core/CartTriggerNotes';

/** From includes/Workflow/Triggers/CartAbandoned.php, which has no fields: only notes to show. */
export const cartAbandonedStep = defineStep({
  id: 'cart_abandoned',
  kind: 'trigger',
  Footer: () => <CartTriggerNotes trigger="cart" />,
});
