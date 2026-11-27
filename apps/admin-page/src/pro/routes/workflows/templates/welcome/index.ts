import { __ } from '@wordpress/i18n';

import { defineTemplate } from '@/routes/workflows/templates/core/defineTemplate';
import { customerEmail, emailStep, lineGraph, waitStep } from '@/routes/workflows/templates/core/flow';
import { shared, welcome } from '@/routes/workflows/templates/welcome';

const newSubject = __('New in the shop', 'yaymatik-automation-for-woocommerce');
const newInShop = customerEmail(newSubject, newSubject, [
  __('Here’s what just arrived at {{ site.name }}:', 'yaymatik-automation-for-woocommerce'),
  '{{ site.new_products }}',
  '{{ site.shop_button }}',
]);

const helpSubject = __('Anything we can help with?', 'yaymatik-automation-for-woocommerce');
const help = customerEmail(helpSubject, helpSubject, [
  __(
    'Reply to this email if you have a question. Your orders and addresses are in your account:',
    'yaymatik-automation-for-woocommerce',
  ),
  '{{ site.account_button }}',
]);

export const welcomeSeries = defineTemplate({
  ...shared,
  id: 'welcome-series',
  name: __('Welcome 3-email series', 'yaymatik-automation-for-woocommerce'),
  description: __(
    'Three emails after a customer creates an account: a welcome right away, new products after 3 days, an offer to help after 7 days.',
    'yaymatik-automation-for-woocommerce',
  ),
  graph: lineGraph('customer_account_created', [
    emailStep('email-1', welcome),
    waitStep('wait-1', 3, 'days'),
    emailStep('email-2', newInShop),
    waitStep('wait-2', 4, 'days'),
    emailStep('email-3', help),
  ]),
});
