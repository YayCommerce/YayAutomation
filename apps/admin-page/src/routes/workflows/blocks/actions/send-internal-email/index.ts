import { __, sprintf } from '@wordpress/i18n';

import { defineBlock, value } from '../../core/defineBlock';
import { splitRecipients } from '../../core/fields/email';
import { SendInternalEmailForm } from './Form';
import { defaults, schema, type SendInternalEmailConfig } from './schema';

const adminEmail = window.yayAutomationMeta.wp.adminEmail;

export const sendInternalEmailBlock = defineBlock<SendInternalEmailConfig>({
  id: 'send_internal_email',
  kind: 'action',
  label: __('Send internal email', 'yay-automation'),
  description: __(
    "Send an email notification to your staff. The email will be sent from your store's contact email address.",
    'yay-automation',
  ),
  schema,
  defaults,
  summary: ({ recipients, subject }) =>
    /* translators: 1: recipient email address(es), 2: email subject */
    sprintf(
      __('Send email to %1$s with the following subject: %2$s', 'yay-automation'),
      value(splitRecipients(recipients).join(', ') || adminEmail),
      value(subject),
    ),
  Form: SendInternalEmailForm,
});
