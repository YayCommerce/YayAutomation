import { __ } from '@wordpress/i18n';

import { defineTemplate } from '../core/defineTemplate';
import { customerEmail, emailStep, lineGraph } from '../core/flow';

export const shared = { category: 'customer_lifecycle', workflow: 'welcome', createdAt: '2026-11-03' } as const;

const welcomeSubject = __('Welcome to {{ site.name }}', 'yaymatik');
export const welcome = customerEmail(welcomeSubject, welcomeSubject, [
  __('Your account is ready. You can track orders, save addresses and check out faster next time.', 'yaymatik'),
  '{{ site.welcome_links }}',
]);

export const welcomeEmail = defineTemplate({
  ...shared,
  id: 'welcome-email',
  name: __('Welcome email', 'yaymatik'),
  description: __(
    'When a customer creates an account, welcome them with links to their account and the shop.',
    'yaymatik',
  ),
  graph: lineGraph('customer_account_created', [emailStep('email-1', welcome)]),
});
