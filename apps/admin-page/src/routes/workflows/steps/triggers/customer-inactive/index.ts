import { defineStep } from '../../core/defineStep';

const DEFAULT_DAYS = 90;

/** Form and checks come from includes/Workflow/Triggers/CustomerInactive.php `fields()`. */
export const customerInactiveStep = defineStep({
  id: 'customer_inactive',
  kind: 'trigger',
  // Mirrors the PHP step's own `sanitize()`: whole days. Blank is null and stays, so the form shows it as required.
  normalize: ({ days = DEFAULT_DAYS }) => ({ days: typeof days === 'number' ? Math.round(days) : days }),
});
