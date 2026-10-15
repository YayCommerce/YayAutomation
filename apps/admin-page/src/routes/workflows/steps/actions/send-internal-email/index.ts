import { __ } from '@wordpress/i18n';

import { defineStep, value } from '../../core/defineStep';
import { splitRecipients } from '../../core/fields/email';
import { variableNames } from '../../core/summary';

const adminEmail = window.yayAutomationMeta.wp.adminEmail;

/**
 * Form and checks come from includes/Workflow/Steps/Actions/SendInternalEmail.php `fields()`.
 * A new step starts with a sample subject and message; PHP keeps no such text.
 */
export const sendInternalEmailStep = defineStep({
  id: 'send_internal_email',
  kind: 'action',
  defaults: () => ({
    recipients: '',
    subject: __('Order {{ order.number }} is {{ order.status }}', 'yay-automation'),
    message: __(
      'Order {{ order.number }} from {{ customer.full_name }} is {{ order.status }}.\n\nTotal: {{ order.total }}\n\n{{ order.items }}',
      'yay-automation',
    ),
  }),
  // Blank sends to the site admin (PHP `run()`), so the card names that address.
  summaryValues: {
    recipients: ({ recipients }) => value(variableNames(splitRecipients(recipients).join(', ')) || adminEmail),
  },
});
