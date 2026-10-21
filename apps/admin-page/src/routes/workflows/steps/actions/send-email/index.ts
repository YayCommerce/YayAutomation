import { defineStep, value } from '../../core/defineStep';
import { variableNames } from '../../core/summary';
import type { SendEmailConfig } from './schema';

/** Form and checks come from includes/Workflow/Steps/Actions/SendEmail.php `fields()`. */
export const sendEmailStep = defineStep<SendEmailConfig>({
  id: 'send_email',
  kind: 'action',
  summaryValues: {
    to: ({ to }) => value(variableNames(to)),
  },
});
