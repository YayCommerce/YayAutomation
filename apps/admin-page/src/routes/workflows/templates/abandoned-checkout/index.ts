import { __ } from '@wordpress/i18n';

import { defineTemplate } from '../core/defineTemplate';
import { COUPON_LINE, couponStep, customerEmail, emailStep, ITEM_LINES, lineGraph, waitStep } from '../core/flow';

const shared = { category: 'recover_carts', workflow: 'abandoned_checkout', createdAt: '2026-11-02' } as const;

const reminder = customerEmail(
  __('Did something go wrong at checkout?', 'yaymatik-automation-for-woocommerce'),
  __('Finish your order', 'yaymatik-automation-for-woocommerce'),
  [
    __(
      'You were almost done. Your order isn’t placed yet, and your items are saved:',
      'yaymatik-automation-for-woocommerce',
    ),
    ...ITEM_LINES,
    __('If something went wrong, reply to this email and we’ll help.', 'yaymatik-automation-for-woocommerce'),
  ],
);

const coupon = customerEmail(
  __('{{ steps.coupon.amount }} off to finish your order', 'yaymatik-automation-for-woocommerce'),
  __('Your order is still waiting', 'yaymatik-automation-for-woocommerce'),
  [
    __('Use this coupon for {{ steps.coupon.amount }} off your order:', 'yaymatik-automation-for-woocommerce'),
    COUPON_LINE,
    ...ITEM_LINES,
  ],
);

export const checkoutReminder = defineTemplate({
  ...shared,
  id: 'checkout-reminder',
  name: __('Checkout reminder', 'yaymatik-automation-for-woocommerce'),
  description: __(
    'An hour after a checkout is abandoned, email the shopper the order they started. Stops as soon as they order.',
    'yaymatik-automation-for-woocommerce',
  ),
  graph: lineGraph('checkout_abandoned', [waitStep('wait-1', 1, 'hours'), emailStep('email-1', reminder)]),
});

export const checkoutReminderCoupon = defineTemplate({
  ...shared,
  id: 'checkout-reminder-coupon',
  name: __('Checkout reminder + coupon', 'yaymatik-automation-for-woocommerce'),
  description: __(
    'A reminder after 1 hour, then a 10% off coupon valid for 7 days after 1 day. Stops as soon as they order.',
    'yaymatik-automation-for-woocommerce',
  ),
  graph: lineGraph('checkout_abandoned', [
    waitStep('wait-1', 1, 'hours'),
    emailStep('email-1', reminder),
    waitStep('wait-2', 23, 'hours'),
    couponStep(),
    emailStep('email-2', coupon),
  ]),
});
