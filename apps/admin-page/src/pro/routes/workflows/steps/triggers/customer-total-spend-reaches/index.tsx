import { __ } from '@wordpress/i18n';

import { defineStep } from '@/routes/workflows/steps/core/defineStep';
import type { Step } from '@/routes/workflows/steps/core/types';
import { Hints } from '@/routes/workflows/steps/triggers/core/NoSettings';

/** Form and checks come from includes/Pro/Workflow/Triggers/CustomerTotalSpendReaches.php `fields()`. */
export const customerTotalSpendReachesStep = (): Step =>
  defineStep({
    id: 'customer_total_spend_reaches',
    kind: 'trigger',
    Footer: () => (
      <Hints hints={[__('Starts once per customer, on the paid order that reaches this amount.', 'yaymatik')]} />
    ),
  });
