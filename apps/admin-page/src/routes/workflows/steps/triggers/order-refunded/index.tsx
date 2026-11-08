import { __ } from '@wordpress/i18n';

import { defineStep } from '../../core/defineStep';
import { NoSettings } from '../core/NoSettings';

/** From includes/Workflow/Triggers/OrderRefunded.php, which has no fields: only what to know. */
export const orderRefundedStep = defineStep({
  id: 'order_refunded',
  kind: 'trigger',
  Footer: () => (
    <NoSettings
      hints={[__('Use Refund › Full refund in a Condition to tell a full refund from a partial one.', 'yaymatik')]}
    />
  ),
});
