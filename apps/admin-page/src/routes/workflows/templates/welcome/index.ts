import { __ } from '@wordpress/i18n';

import { defineTemplate } from '../core/defineTemplate';
import { customerEmail, emailStep, lineGraph, waitStep } from '../core/flow';

const shared = { category: 'customer_lifecycle', workflow: 'welcome', createdAt: '2026-11-03' } as const;

const welcomeSubject = __('Welcome to {{ site.name }}', 'yaymatik');
const welcome = customerEmail(welcomeSubject, welcomeSubject, [
  __('Your account is ready. You can track orders, save addresses and check out faster next time.', 'yaymatik'),
  '{{ site.welcome_links }}',
]);

const newSubject = __('New in the shop', 'yaymatik');
const newInShop = customerEmail(newSubject, newSubject, [
  __('Here’s what just arrived at {{ site.name }}:', 'yaymatik'),
  '{{ site.new_products }}',
  '{{ site.shop_button }}',
]);

const helpSubject = __('Anything we can help with?', 'yaymatik');
const help = customerEmail(helpSubject, helpSubject, [
  __('Reply to this email if you have a question. Your orders and addresses are in your account:', 'yaymatik'),
  '{{ site.account_button }}',
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

export const welcomeSeries = defineTemplate({
  ...shared,
  id: 'welcome-series',
  name: __('Welcome 3-email series', 'yaymatik'),
  description: __(
    'Three emails after a customer creates an account: a welcome right away, new products after 3 days, an offer to help after 7 days.',
    'yaymatik',
  ),
  graph: lineGraph('customer_account_created', [
    emailStep('email-1', welcome),
    waitStep('wait-1', 3, 'days'),
    emailStep('email-2', newInShop),
    waitStep('wait-2', 4, 'days'),
    emailStep('email-3', help),
  ]),
});
