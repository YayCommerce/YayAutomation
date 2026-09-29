import { __, sprintf } from '@wordpress/i18n';

import { defineBlock } from '../../core/defineBlock';
import { SendAdminEmailForm } from './Form';
import { defaults, schema, splitRecipients, type SendAdminEmailConfig } from './schema';

export const sendAdminEmailBlock = defineBlock<SendAdminEmailConfig>({
  id: 'send_admin_email',
  kind: 'action',
  app: 'email',
  label: __('Send admin email', 'yay-automation'),
  description: __('Sends a plain-text email about the order to the store team.', 'yay-automation'),
  schema,
  defaults,
  summary: ({ recipients }) => {
    const emails = splitRecipients(recipients);
    return emails.length
      ? /* translators: %s: comma-separated email addresses */
        sprintf(__('Send an email to %s', 'yay-automation'), emails.join(', '))
      : __('Send an email to the site admin', 'yay-automation');
  },
  Form: SendAdminEmailForm,
});
