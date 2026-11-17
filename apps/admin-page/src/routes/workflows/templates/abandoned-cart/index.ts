import { __ } from '@wordpress/i18n';

import { defineTemplate } from '../core/defineTemplate';
import { customerEmail, emailStep, ITEM_LINES, lineGraph, waitStep } from '../core/flow';

export const shared = { category: 'recover_carts', workflow: 'abandoned_cart', createdAt: '2026-11-02' } as const;

export const first = customerEmail(
  __('You left something in your cart', 'yaymatik'),
  __('Your cart is saved', 'yaymatik'),
  [__('We saved the items you left at {{ site.name }}:', 'yaymatik'), ...ITEM_LINES],
);

export const cartReminder = defineTemplate({
  ...shared,
  id: 'cart-reminder',
  name: __('Cart reminder', 'yaymatik'),
  description: __(
    'An hour after a cart is abandoned, email the shopper their cart. Stops as soon as they order.',
    'yaymatik',
  ),
  graph: lineGraph('cart_abandoned', [waitStep('wait-1', 1, 'hours'), emailStep('email-1', first)]),
});
