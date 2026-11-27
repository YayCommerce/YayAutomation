import { __, sprintf } from '@wordpress/i18n';

import { defineStep } from '@/routes/workflows/steps/core/defineStep';
import { catalogLabel } from '@/routes/workflows/steps/core/summary';
import type { Step } from '@/routes/workflows/steps/core/types';
import { Hints } from '@/routes/workflows/steps/triggers/core/NoSettings';

/** Form and checks come from includes/Pro/Workflow/Actions/ResendWcEmail.php `fields()`: only customer order emails are listed. */
export const resendWcEmailStep = (): Step =>
  defineStep({
    id: 'resend_wc_email',
    kind: 'action',
    result: (step) =>
      /* translators: %s: a WooCommerce email's name, e.g. "Completed order" */
      sprintf(
        __('Sent %s', 'yaymatik-automation-for-woocommerce'),
        catalogLabel('resend_wc_email', 'email', String(step.output.email ?? '')),
      ),
    Footer: () => (
      <Hints
        hints={[
          __(
            'Sent to the order’s billing email, as WooCommerce sends it. Skipped when the email is turned off in WooCommerce.',
            'yaymatik-automation-for-woocommerce',
          ),
        ]}
      />
    ),
  });
