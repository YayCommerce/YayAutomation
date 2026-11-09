import { __ } from '@wordpress/i18n';

import { defineStep } from '../../core/defineStep';
import type { Step } from '../../core/types';
import { Hints } from '../core/NoSettings';

/** Form and checks come from includes/Workflow/Triggers/CustomerOrderCountReaches.php `fields()`. */
export const customerOrderCountReachesStep: Step = defineStep({
  id: 'customer_order_count_reaches',
  kind: 'trigger',
  // A whole number, like the PHP step's own `sanitize()`; a blank stays so the form asks for one.
  normalize: ({ count = customerOrderCountReachesStep.defaults().count }) => ({
    count: typeof count === 'number' ? Math.round(count) : count,
  }),
  Footer: () => (
    <Hints
      hints={[
        __('Customers already past this number don’t start it. For them, use Order paid with a condition.', 'yaymatik'),
      ]}
    />
  ),
});
