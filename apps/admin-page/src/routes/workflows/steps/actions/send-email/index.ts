import { defineStep, value } from '../../core/defineStep';
import { variableNames } from '../../core/summary';
import { EmailActions, UnsubscribeNote } from './EmailActions';
import type { SendEmailConfig } from './schema';

/**
 * Form and checks come from includes/Workflow/Steps/Actions/SendEmail.php `fields()`, with the
 * unsubscribe note under them; Preview and Send test in the panel header.
 */
export const sendEmailStep = defineStep<SendEmailConfig>({
  id: 'send_email',
  kind: 'action',
  summaryValues: {
    to: ({ to }) => value(variableNames(to)),
  },
  Footer: UnsubscribeNote,
  Actions: EmailActions,
});
