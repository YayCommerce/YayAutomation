import type { WorkflowGraph } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

import type { GenerateCouponConfig } from '../../blocks/actions/generate-coupon/schema';
import type { SendEmailConfig } from '../../blocks/actions/send-email/schema';
import { BRANCH_OFFSET, ROW_GAP } from '../../model/graph';
import { defineTemplate } from '../core/defineTemplate';

/**
 * Thank a customer for their first completed order with a coupon for the next one. Two versions
 * that differ only in the coupon and the line that offers it.
 */
function graph(coupon: Partial<GenerateCouponConfig>, offer: string): WorkflowGraph {
  const email: SendEmailConfig = {
    to: '{{ customer.email }}',
    subject: __('Thank you for your first order, {{ customer.first_name }}!', 'yay-automation'),
    heading: __('Thanks for shopping with us', 'yay-automation'),
    message: [
      __('Hi {{ customer.first_name }},', 'yay-automation'),
      __('Thank you for your first order with {{ site.name }}. We hope you love it!', 'yay-automation'),
      offer,
      __('Coupon code: {{ coupon.code }}\nExpires: {{ coupon.expiry_date }}', 'yay-automation'),
      __('See you again soon,\n{{ site.name }}', 'yay-automation'),
    ].join('\n\n'),
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
              { field: 'customer.paid_order_count', operator: 'equal', value: '1', valueType: 'value' },
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
          config: {
            discount_type: 'percent',
            amount: '10',
            expiry_days: '30',
            minimum_spend: '',
            individual_use: false,
            limit_to_customer: true,
            code_prefix: 'THANKS-',
            ...coupon,
          } satisfies GenerateCouponConfig,
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
  name: __('First-order thank-you: % off next order', 'yay-automation'),
  description: __(
    'Thank customers when their first order is completed and give them 10% off their next order, so they come back.',
    'yay-automation',
  ),
  category: 'post_purchase',
  popularity: 0,
  createdAt: '2026-10-07',
  graph: graph(
    { discount_type: 'percent', amount: '10' },
    __('As a thank you, here is {{ coupon.amount }} off your next order:', 'yay-automation'),
  ),
});

export const firstOrderFreeShipping = defineTemplate({
  id: 'first-order-thank-you-free-shipping',
  name: __('First-order thank-you: free shipping', 'yay-automation'),
  description: __(
    'Thank customers when their first order is completed and give them free shipping on their next order, so they come back.',
    'yay-automation',
  ),
  category: 'post_purchase',
  popularity: 0,
  createdAt: '2026-10-07',
  graph: graph(
    { discount_type: 'free_shipping', amount: '' },
    __('As a thank you, your next order ships free:', 'yay-automation'),
  ),
});
