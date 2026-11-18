import { expect, it } from 'vitest';

import { templates } from '@/routes/workflows/templates';

it('has the 8 templates of the free edition, each valid with its steps only', () => {
  expect(templates.map((template) => template.id)).toEqual([
    'cart-reminder',
    'checkout-reminder',
    'checkout-reminder-coupon',
    'high-value-order-alert',
    'first-order-thank-you-discount',
    'first-order-thank-you-free-shipping',
    'review-request',
    'welcome-email',
  ]);
});
