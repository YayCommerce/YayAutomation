import { __ } from '@wordpress/i18n';

import { defineStep } from '../../core/defineStep';
import { Hints } from '../core/NoSettings';

/** Form and checks come from includes/Workflow/Triggers/CustomerTotalSpendReaches.php `fields()`. */
export const customerTotalSpendReachesStep = defineStep({
  id: 'customer_total_spend_reaches',
  kind: 'trigger',
  Footer: () => (
    <Hints hints={[__('Starts once per customer, on the paid order that reaches this amount.', 'yaymatik')]} />
  ),
});
