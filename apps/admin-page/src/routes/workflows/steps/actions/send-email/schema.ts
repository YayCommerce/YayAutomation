import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import { isWpEmail, splitRecipients } from '../../core/fields/email';
import { required, withKnownVariables } from '../../core/fields/schema';
import { isPendingStep } from '../../core/pending';

/**
 * Email to the customer, wrapped in the WooCommerce email template with an unsubscribe link added.
 * `to` holds addresses or merge tags (e.g. `{{ customer.email }}`), comma-separated; blank `heading`
 * = the subject. Its PHP side isn't built yet (see `pending.ts`).
 */
export type SendEmailConfig = { to: string; subject: string; heading: string; message: string };

export const schema = withKnownVariables(
  v.object({
    to: v.pipe(
      required(),
      v.check(
        // Like PHP `Field::emails`: a part with a tag is a variable; the merge-tag check reads it.
        (to) => splitRecipients(to).every((part) => part.includes('{{') || isWpEmail(part)),
        __('Enter email addresses or variables, separated by commas.', 'yay-automation'),
      ),
    ),
    subject: required(),
    heading: v.string(),
    message: required(),
  }),
  ['to', 'subject', 'heading', 'message'],
  // While the step itself is coming soon, it may use fields that are too.
  { allowPending: isPendingStep('send_email') },
);

export const defaults = (): SendEmailConfig => ({
  to: '{{ customer.email }}',
  subject: '',
  heading: '',
  message: '',
});
