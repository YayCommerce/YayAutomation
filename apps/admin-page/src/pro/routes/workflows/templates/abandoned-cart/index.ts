import { __ } from '@wordpress/i18n';

import { first, shared } from '@/routes/workflows/templates/abandoned-cart';
import { defineTemplate } from '@/routes/workflows/templates/core/defineTemplate';
import {
  COUPON_LINE,
  couponStep,
  customerEmail,
  emailStep,
  ITEM_LINES,
  lineGraph,
  waitStep,
} from '@/routes/workflows/templates/core/flow';

const second = customerEmail(__('Still thinking it over?', 'yaymatik'), __('Your cart is waiting', 'yaymatik'), [
  __('Your cart is still saved. Pick up where you left off:', 'yaymatik'),
  ...ITEM_LINES,
]);

const last = customerEmail(__('Last reminder about your cart', 'yaymatik'), __('Your cart is still here', 'yaymatik'), [
  __('This is our last reminder. Your items are one click away:', 'yaymatik'),
  ...ITEM_LINES,
]);

const lastCoupon = customerEmail(
  __('{{ steps.coupon.amount }} off the cart you left', 'yaymatik'),
  __('A little something to help you decide', 'yaymatik'),
  [__('Use this coupon for {{ steps.coupon.amount }} off your order:', 'yaymatik'), COUPON_LINE, ...ITEM_LINES],
);

export const cartSeries = defineTemplate({
  ...shared,
  id: 'cart-series',
  name: __('Cart 3-email series', 'yaymatik'),
  description: __(
    'Three reminders with the shopper’s cart: after 1 hour, 1 day and 3 days. Stops as soon as they order.',
    'yaymatik',
  ),
  graph: lineGraph('cart_abandoned', [
    waitStep('wait-1', 1, 'hours'),
    emailStep('email-1', first),
    waitStep('wait-2', 23, 'hours'),
    emailStep('email-2', second),
    waitStep('wait-3', 2, 'days'),
    emailStep('email-3', last),
  ]),
});

export const cartSeriesCoupon = defineTemplate({
  ...shared,
  id: 'cart-series-coupon',
  name: __('Cart 3-email series + coupon', 'yaymatik'),
  description: __(
    'Three reminders with the shopper’s cart; the last adds a 10% off coupon valid for 7 days. Stops as soon as they order.',
    'yaymatik',
  ),
  graph: lineGraph('cart_abandoned', [
    waitStep('wait-1', 1, 'hours'),
    emailStep('email-1', first),
    waitStep('wait-2', 23, 'hours'),
    emailStep('email-2', second),
    waitStep('wait-3', 2, 'days'),
    couponStep(),
    emailStep('email-3', lastCoupon),
  ]),
});
