import { __ } from '@wordpress/i18n';

import { defineTemplate } from '../core/defineTemplate';
import { customerEmail, emailStep, ITEM_LINES, lineGraph, waitStep } from '../core/flow';

export const shared = { category: 'recover_carts', workflow: 'abandoned_cart', createdAt: '2026-11-02' } as const;

export const first = customerEmail(
  __('You left something in your cart', 'yaymatik-automation-for-woocommerce'),
  __('Your cart is saved', 'yaymatik-automation-for-woocommerce'),
  [__('We saved the items you left at {{ site.name }}:', 'yaymatik-automation-for-woocommerce'), ...ITEM_LINES],
);

export const cartReminder = defineTemplate({
  ...shared,
  id: 'cart-reminder',
  name: __('Cart reminder', 'yaymatik-automation-for-woocommerce'),
  description: __(
    'An hour after a cart is abandoned, email the shopper their cart. Stops as soon as they order.',
    'yaymatik-automation-for-woocommerce',
  ),
  graph: lineGraph('cart_abandoned', [waitStep('wait-1', 1, 'hours'), emailStep('email-1', first)]),
});
