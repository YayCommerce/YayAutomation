import type { WorkflowGraph } from '@yaymatik/types';
import { __ } from '@wordpress/i18n';

import { BRANCH_OFFSET, ROW_GAP } from '../../model/graph';
import { generateCouponStep, type GenerateCouponConfig } from '../../steps/actions/generate-coupon';
import type { SendEmailConfig } from '../../steps/actions/send-email/schema';
import { defineTemplate } from '../core/defineTemplate';
import { emailMessage } from '../core/email';

/**
 * Thank a customer for their first completed order with a coupon for the next one. Two versions
 * that differ only in the coupon and the line that offers it.
 */
function graph(coupon: Partial<GenerateCouponConfig>, offer: string): WorkflowGraph {
  const email: SendEmailConfig = {
    to: '{{ customer.email }}',
    subject: __('Thanks for your first order — a gift for your next one', 'yaymatik-automation-for-woocommerce'),
    heading: __('Thanks for shopping with us', 'yaymatik-automation-for-woocommerce'),
    message: emailMessage([
      __('Hi {{ customer.first_name | default: "there" }},', 'yaymatik-automation-for-woocommerce'),
      __(
        'Thank you for your first order with {{ site.name }}. We hope you love it!',
        'yaymatik-automation-for-woocommerce',
      ),
      offer,
      '{{ steps.coupon.box }}',
      __('Enter the code at checkout to use it.', 'yaymatik-automation-for-woocommerce'),
      __('See you again soon,\n{{ site.name }}', 'yaymatik-automation-for-woocommerce'),
    ]),
  };

  return {
    nodes: [
      {
        id: 'trigger',
        kind: 'trigger',
        position: { x: 0, y: 0 },
        data: { block: 'order_status_changed', config: { from: [], to: ['completed'], only_checkout: false } },
      },
      {
        id: 'condition',
        kind: 'condition',
        position: { x: 0, y: ROW_GAP },
        data: {
          block: 'condition',
          config: {
            match: 'all',
            rules: [
              { field: 'customer.is_first_order', operator: 'is_true', value: '', valueType: 'value' },
              { field: 'customer.unsubscribed', operator: 'is_false', value: '', valueType: 'value' },
            ],
          },
        },
      },
      {
        id: 'coupon',
        kind: 'action',
        position: { x: -BRANCH_OFFSET, y: ROW_GAP * 2 },
        data: {
          block: 'generate_coupon',
          config: { ...generateCouponStep.defaults(), expiry_days: 30, ...coupon },
        },
      },
      {
        id: 'email',
        kind: 'action',
        position: { x: -BRANCH_OFFSET, y: ROW_GAP * 3 },
        data: { block: 'send_email', config: email },
      },
    ],
    edges: [
      { id: 'e-trigger-condition', source: 'trigger', target: 'condition' },
      { id: 'e-condition-coupon', source: 'condition', target: 'coupon', sourceHandle: 'then' },
      { id: 'e-coupon-email', source: 'coupon', target: 'email' },
    ],
  };
}

export const firstOrderDiscount = defineTemplate({
  id: 'first-order-thank-you-discount',
  name: __('First-order thank-you: % off next order', 'yaymatik-automation-for-woocommerce'),
  description: __(
    'Thank customers when their first order is completed, with a 10% off coupon for their next order, valid for 30 days.',
    'yaymatik-automation-for-woocommerce',
  ),
  category: 'post_purchase',
  workflow: 'first_order_thank_you',
  createdAt: '2026-10-07',
  graph: graph(
    { discount_type: 'percent', amount: 10 },
    __('As a thank you, here is {{ steps.coupon.amount }} off your next order:', 'yaymatik-automation-for-woocommerce'),
  ),
});

export const firstOrderFreeShipping = defineTemplate({
  id: 'first-order-thank-you-free-shipping',
  name: __('First-order thank-you: free shipping', 'yaymatik-automation-for-woocommerce'),
  description: __(
    'Thank customers when their first order is completed, with a free shipping coupon for their next order, valid for 30 days.',
    'yaymatik-automation-for-woocommerce',
  ),
  category: 'post_purchase',
  workflow: 'first_order_thank_you',
  createdAt: '2026-10-07',
  graph: graph(
    { discount_type: 'free_shipping', amount: null },
    __('As a thank you, your next order ships free:', 'yaymatik-automation-for-woocommerce'),
  ),
});
