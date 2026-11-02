import { __ } from '@wordpress/i18n';

import { cartEmail, cartGraph, COUPON_LINE, couponStep, emailStep, ITEM_LINES, waitStep } from '../core/cartFlow';
import { defineTemplate } from '../core/defineTemplate';

const shared = { category: 'recover_carts', workflow: 'abandoned_checkout', createdAt: '2026-11-02' } as const;

const reminder = cartEmail(__('Did something go wrong at checkout?', 'yaymatik'), __('Finish your order', 'yaymatik'), [
  __('You were almost done. Your order isn’t placed yet, and your items are saved:', 'yaymatik'),
  ...ITEM_LINES,
  __('If something went wrong, reply to this email and we’ll help.', 'yaymatik'),
]);

const coupon = cartEmail(
  __('{{ steps.coupon.amount }} off to finish your order', 'yaymatik'),
  __('Your order is still waiting', 'yaymatik'),
  [__('Use this coupon for {{ steps.coupon.amount }} off your order:', 'yaymatik'), COUPON_LINE, ...ITEM_LINES],
);

export const checkoutReminder = defineTemplate({
  ...shared,
  id: 'checkout-reminder',
  name: __('Checkout reminder', 'yaymatik'),
  description: __(
    'An hour after a checkout is abandoned, email the shopper the order they started. Stops as soon as they order.',
    'yaymatik',
  ),
  graph: cartGraph('checkout_abandoned', [waitStep('wait-1', 1, 'hours'), emailStep('email-1', reminder)]),
});

export const checkoutReminderCoupon = defineTemplate({
  ...shared,
  id: 'checkout-reminder-coupon',
  name: __('Checkout reminder + coupon', 'yaymatik'),
  description: __(
    'A reminder after 1 hour, then a 10% off coupon valid for 7 days after 1 day. Stops as soon as they order.',
    'yaymatik',
  ),
  graph: cartGraph('checkout_abandoned', [
    waitStep('wait-1', 1, 'hours'),
    emailStep('email-1', reminder),
    waitStep('wait-2', 23, 'hours'),
    couponStep(),
    emailStep('email-2', coupon),
  ]),
});
