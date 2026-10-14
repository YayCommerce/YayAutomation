import { __, sprintf } from '@wordpress/i18n';

import { defineStep, value } from '../../core/defineStep';
import { splitRecipients } from '../../core/fields/email';
import { SendEmailForm } from './Form';
import { defaults, schema, type SendEmailConfig } from './schema';

export const sendEmailStep = defineStep<SendEmailConfig>({
  id: 'send_email',
  kind: 'action',
  label: __('Send email', 'yay-automation'),
  description: __(
    'Send an email to a customer in your store’s WooCommerce email style, with an unsubscribe link.',
    'yay-automation',
  ),
  schema,
  defaults,
  summary: ({ to }) =>
    /* translators: %s: recipient(s) */
    sprintf(__('Send to %s', 'yay-automation'), value(splitRecipients(to).join(', '))),
  Form: SendEmailForm,
});
