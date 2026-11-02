import { defineStep } from '../../core/defineStep';
import { CartTriggerNotes } from '../core/CartTriggerNotes';

/** From includes/Workflow/Triggers/CheckoutAbandoned.php, which has no fields: only notes to show. */
export const checkoutAbandonedStep = defineStep({
  id: 'checkout_abandoned',
  kind: 'trigger',
  Footer: () => <CartTriggerNotes trigger="checkout" />,
});
