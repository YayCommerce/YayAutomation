import { __ } from '@wordpress/i18n';

import { defineTemplate } from '@/routes/workflows/templates/core/defineTemplate';
import { customerEmail } from '@/routes/workflows/templates/core/flow';
import { graph, shared } from '@/routes/workflows/templates/review-request';

const second = customerEmail(
  __('A quick review?', 'yaymatik-automation-for-woocommerce'),
  __('Still have a minute?', 'yaymatik-automation-for-woocommerce'),
  [__('You haven’t reviewed these yet:', 'yaymatik-automation-for-woocommerce'), '{{ order.review_products }}'],
);

export const reviewRequestReminder = defineTemplate({
  ...shared,
  id: 'review-request-reminder',
  name: __('Review request + reminder', 'yaymatik-automation-for-woocommerce'),
  description: __(
    '7 days after an order is completed, ask for a review of each product; a week later, remind once about the ones still not reviewed.',
    'yaymatik-automation-for-woocommerce',
  ),
  graph: graph(second),
});
