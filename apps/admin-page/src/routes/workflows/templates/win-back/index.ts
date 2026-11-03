import type { WorkflowGraph } from '@yaymatik/types';
import { __ } from '@wordpress/i18n';

import { BRANCH_OFFSET, ROW_GAP } from '../../model/graph';
import { generateCouponStep, type GenerateCouponConfig } from '../../steps/actions/generate-coupon';
import { defineTemplate } from '../core/defineTemplate';
import { COUPON_LINE, customerEmail, rule } from '../core/flow';

const shared = { category: 'customer_lifecycle', workflow: 'win_back', createdAt: '2026-11-03' } as const;

/** Bring back a customer whose last order is 90 days old. Two versions that differ only in the coupon and the line that offers it. */
function graph(coupon: Partial<GenerateCouponConfig>, offer: string): WorkflowGraph {
  const email = customerEmail(__('We miss you at {{ site.name }}', 'yaymatik'), __('It’s been a while', 'yaymatik'), [
    offer,
    COUPON_LINE,
    '{{ site.shop_button }}',
  ]);

  return {
    nodes: [
      {
        id: 'trigger',
        kind: 'trigger',
        position: { x: 0, y: 0 },
        data: { block: 'customer_inactive', config: { days: 90 } },
      },
      {
        id: 'condition',
        kind: 'condition',
        position: { x: 0, y: ROW_GAP },
        data: {
          block: 'condition',
          config: { match: 'all', rules: [rule('customer.unsubscribed', 'is_false')] },
        },
      },
      {
        id: 'coupon',
        kind: 'action',
        position: { x: -BRANCH_OFFSET, y: ROW_GAP * 2 },
        data: {
          block: 'generate_coupon',
          config: { ...generateCouponStep.defaults(), expiry_days: 14, ...coupon },
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

export const winBackDiscount = defineTemplate({
  ...shared,
  id: 'win-back-discount',
  name: __('Win-back + discount', 'yaymatik'),
  description: __('When a customer hasn’t ordered for 90 days, send a 15% off coupon, valid for 14 days.', 'yaymatik'),
  graph: graph(
    { discount_type: 'percent', amount: 15 },
    __('It’s been a while since your last order. Here’s {{ steps.coupon.amount }} off your next one:', 'yaymatik'),
  ),
});

export const winBackFreeShipping = defineTemplate({
  ...shared,
  id: 'win-back-free-shipping',
  name: __('Win-back + free shipping', 'yaymatik'),
  description: __(
    'When a customer hasn’t ordered for 90 days, send a free shipping coupon, valid for 14 days.',
    'yaymatik',
  ),
  graph: graph(
    { discount_type: 'free_shipping', amount: null },
    __('It’s been a while since your last order. Here’s free shipping on your next order:', 'yaymatik'),
  ),
});
