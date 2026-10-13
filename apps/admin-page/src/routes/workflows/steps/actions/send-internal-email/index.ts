import { defineStep, value } from '../../core/defineStep';
import { splitRecipients } from '../../core/fields/email';
import { SendInternalEmailForm } from './Form';
import { defaults, schema, type SendInternalEmailConfig } from './schema';

const adminEmail = window.yayAutomationMeta.wp.adminEmail;

export const sendInternalEmailStep = defineStep<SendInternalEmailConfig>({
  id: 'send_internal_email',
  kind: 'action',
  schema,
  defaults,
  // Blank sends to the site admin (PHP `run()`), so the card names that address.
  summaryValues: { recipients: ({ recipients }) => value(splitRecipients(recipients).join(', ') || adminEmail) },
  Form: SendInternalEmailForm,
});
