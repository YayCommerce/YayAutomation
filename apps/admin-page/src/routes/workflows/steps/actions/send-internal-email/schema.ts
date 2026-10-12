import * as v from 'valibot';
import { __, sprintf } from '@wordpress/i18n';

import { messages } from '../../../model/messages';
import { isWpEmail, splitRecipients } from '../../core/fields/email';
import { requiredFor, withKnownVariables } from '../../core/fields/schema';

/** Mirrors includes/Workflow/Steps/Actions/SendInternalEmail.php. Blank `recipients` = the site admin email. */
export type SendInternalEmailConfig = { recipients: string; subject: string; message: string };

export const schema = withKnownVariables(
  v.object({
    recipients: v.pipe(
      v.string(),
      // The first address that isn't valid, like PHP.
      v.rawCheck(({ dataset, addIssue }) => {
        if (!dataset.typed) return;
        const invalid = splitRecipients(dataset.value).find((email) => !isWpEmail(email));
        if (invalid !== undefined) addIssue({ message: sprintf(messages.invalidEmail, invalid) });
      }),
    ),
    /* translators: %s: step name, e.g. "Send internal email" */
    subject: requiredFor('send_internal_email', __('%s needs a subject.', 'yay-automation')),
    /* translators: %s: step name, e.g. "Send internal email" */
    message: requiredFor('send_internal_email', __('%s needs a message.', 'yay-automation')),
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
