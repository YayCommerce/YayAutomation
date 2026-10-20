import { defineStep, value } from '../../core/defineStep';
import { splitRecipients } from '../../core/fields/email';
import { variableNames } from '../../core/summary';

const adminEmail = window.yayMatikMeta.wp.adminEmail;

/**
 * Form, checks and the new step's sample subject and message come from
 * includes/Workflow/Steps/Actions/SendInternalEmail.php `fields()`.
 */
export const sendInternalEmailStep = defineStep<{ recipients: string; subject: string; message: string }>({
  id: 'send_internal_email',
  kind: 'action',
  // Blank sends to the site admin (PHP `run()`), so the card names that address.
  summaryValues: {
    recipients: ({ recipients }) => value(variableNames(splitRecipients(recipients).join(', ')) || adminEmail),
  },
});
