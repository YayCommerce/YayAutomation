import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import { isWpEmail, splitRecipients } from '../../core/fields/email';
import { required, withKnownVariables } from '../../core/fields/schema';
import { isPendingBlock } from '../../core/pending';

/**
 * Email to the customer, wrapped in the WooCommerce email template with an unsubscribe link added.
 * `to` holds addresses or merge tags (e.g. `{{ customer.email }}`), comma-separated; blank `heading`
 * = the subject. Its PHP side isn't built yet (see `pending.ts`).
 */
export type SendEmailConfig = { to: string; subject: string; heading: string; message: string };

const TAG = /^\{\{\s*[a-z_]+\.[a-z_]+\s*\}\}$/;

export const schema = withKnownVariables(
  v.object({
    to: v.pipe(
      required(),
      v.check(
        (to) => splitRecipients(to).every((part) => TAG.test(part) || isWpEmail(part)),
        __('Enter email addresses or variables, separated by commas.', 'yay-automation'),
      ),
    ),
    subject: required(),
    heading: v.string(),
    message: required(),
  }),
  ['to', 'subject', 'heading', 'message'],
  // While the block itself is coming soon, it may use fields that are too.
  { allowPending: isPendingBlock('send_email') },
);

export const defaults = (): SendEmailConfig => ({
  to: '{{ customer.email }}',
  subject: '',
  heading: '',
  message: '',
});
