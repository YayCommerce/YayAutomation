import { __ } from '@wordpress/i18n';

import { defineStep } from '@/routes/workflows/steps/core/defineStep';
import type { Step } from '@/routes/workflows/steps/core/types';
import { Hints } from '@/routes/workflows/steps/triggers/core/NoSettings';

/** Form and checks come from includes/Pro/Workflow/Triggers/CustomerOrderCountReaches.php `fields()`. */
export const customerOrderCountReachesStep = (): Step => {
  const step: Step = defineStep({
    id: 'customer_order_count_reaches',
    kind: 'trigger',
    // A whole number, like the PHP step's own `sanitize()`; a blank stays so the form asks for one.
    normalize: ({ count = step.defaults().count }) => ({
      count: typeof count === 'number' ? Math.round(count) : count,
    }),
    Footer: () => (
      <Hints
        hints={[
          __(
            'Customers already past this number don’t start it. For them, use Order paid with a condition.',
            'yaymatik',
          ),
        ]}
      />
    ),
  });
  return step;
};
