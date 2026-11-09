import { __ } from '@wordpress/i18n';

import { defineStep } from '../../core/defineStep';
import { NoSettings } from '../core/NoSettings';

/** From includes/Workflow/Triggers/OrderPaid.php, which has no fields: only what to know. */
export const orderPaidStep = defineStep({
  id: 'order_paid',
  kind: 'trigger',
  Footer: () => (
    <NoSettings
      hints={[__('Paid means Processing or Completed. Orders imported already paid don’t start it.', 'yaymatik')]}
    />
  ),
});
