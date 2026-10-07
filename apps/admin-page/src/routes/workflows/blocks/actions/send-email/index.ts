import { __, sprintf } from '@wordpress/i18n';

import { defineBlock, value } from '../../core/defineBlock';
import { splitRecipients } from '../../core/fields/email';
import { SendEmailForm } from './Form';
import { defaults, schema, type SendEmailConfig } from './schema';

export const sendEmailBlock = defineBlock<SendEmailConfig>({
  id: 'send_email',
  kind: 'action',
  label: __('Send email', 'yay-automation'),
  description: __(
    'Send an email to a customer in your store’s WooCommerce email style, with an unsubscribe link.',
    'yay-automation',
  ),
  schema,
  defaults,
  summary: ({ to, subject }) =>
    /* translators: 1: recipient(s), 2: email subject */
    sprintf(
      __('Send email to %1$s with the following subject: %2$s', 'yay-automation'),
      value(splitRecipients(to).join(', ')),
      value(subject),
    ),
  Form: SendEmailForm,
});
