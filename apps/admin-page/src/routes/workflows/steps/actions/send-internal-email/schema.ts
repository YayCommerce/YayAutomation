import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import { isWpEmail, splitRecipients } from '../../core/fields/email';
import { required, withKnownVariables } from '../../core/fields/schema';

/** Mirrors includes/Workflow/Steps/Actions/SendInternalEmail.php. Blank `recipients` = the site admin email. */
export type SendInternalEmailConfig = { recipients: string; subject: string; message: string };

export const schema = withKnownVariables(
  v.object({
    recipients: v.pipe(
      v.string(),
      v.check(
        (recipients) => splitRecipients(recipients).every(isWpEmail),
        __('Enter valid email addresses, separated by commas.', 'yay-automation'),
      ),
    ),
    subject: required(),
    message: required(),
  }),
  ['subject', 'message'],
);

export const defaults = (): SendInternalEmailConfig => ({
  recipients: '',
  subject: __('Order {{ order.number }} is {{ order.status }}', 'yay-automation'),
  message: __(
    'Order {{ order.number }} from {{ customer.full_name }} is {{ order.status }}.\n\nTotal: {{ order.total }}\n\n{{ order.items }}',
    'yay-automation',
  ),
});
