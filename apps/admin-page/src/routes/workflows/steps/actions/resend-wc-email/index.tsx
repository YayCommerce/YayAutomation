import { __ } from '@wordpress/i18n';

import { defineStep } from '../../core/defineStep';
import { Hints } from '../../triggers/core/NoSettings';

/** Form and checks come from includes/Workflow/Actions/ResendWcEmail.php `fields()`: only customer order emails are listed. */
export const resendWcEmailStep = defineStep({
  id: 'resend_wc_email',
  kind: 'action',
  Footer: () => (
    <Hints
      hints={[
        __(
          'Sent to the order’s billing email, as WooCommerce sends it. Skipped when the email is turned off in WooCommerce.',
          'yaymatik',
        ),
      ]}
    />
  ),
});
