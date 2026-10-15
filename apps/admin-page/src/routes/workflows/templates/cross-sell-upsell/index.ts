import type { StepId, WorkflowEdge, WorkflowGraph, WorkflowNode } from '@yaymatik/types';
import { __ } from '@wordpress/i18n';

import { BRANCH_OFFSET, ROW_GAP } from '../../model/graph';
import { defaults as couponDefaults, type GenerateCouponConfig } from '../../steps/actions/generate-coupon/schema';
import type { SendEmailConfig } from '../../steps/actions/send-email/schema';
import type { WaitConfig } from '../../steps/actions/wait/schema';
import type { Rule } from '../../steps/conditions/criteria/schema';
import { defineTemplate } from '../core/defineTemplate';

const rule = (field: string, operator: string, value = ''): Rule => ({ field, operator, value, valueType: 'value' });

/**
 * Suggest more products some time after a completed order: check who it's for and that there is
 * something to suggest, wait, then check again with fresh data (still completed, still something
 * to suggest, not unsubscribed meanwhile), then [coupon] → email.
 */
function graph({
  audience = [],
  suggestions,
  days,
  coupon,
  email,
}: {
  /** Who the template is for, checked before waiting. */
  audience?: Rule[];
  /** "Has cross-sells / upsells", checked before and after waiting. */
  suggestions: Rule;
  days: number;
  coupon?: Partial<GenerateCouponConfig>;
  email: SendEmailConfig;
}): WorkflowGraph {
  // Each "then" branch steps left, like steps added in the editor.
  const step = (
    id: string,
    column: number,
    row: number,
    kind: WorkflowNode['kind'],
    stepId: StepId,
    config: Record<string, unknown>,
  ): WorkflowNode => ({
    id,
    kind,
    position: { x: -BRANCH_OFFSET * column, y: ROW_GAP * row },
    data: { block: stepId, config },
  });

  const nodes: WorkflowNode[] = [
    step('trigger', 0, 0, 'trigger', 'order_status_changed', { from: [], to: ['completed'], only_checkout: false }),
    step('condition', 0, 1, 'condition', 'condition', { match: 'all', rules: [...audience, suggestions] }),
    step('wait', 1, 2, 'action', 'wait', { amount: String(days), unit: 'days' } satisfies WaitConfig),
    step('still-valid', 1, 3, 'condition', 'condition', {
      match: 'all',
      rules: [rule('order.status', 'is', 'completed'), suggestions, rule('customer.unsubscribed', 'is_false')],
    }),
  ];
  const edges: WorkflowEdge[] = [
    { id: 'e-trigger-condition', source: 'trigger', target: 'condition' },
    { id: 'e-condition-wait', source: 'condition', target: 'wait', sourceHandle: 'then' },
    { id: 'e-wait-still-valid', source: 'wait', target: 'still-valid' },
  ];

  let last = 'still-valid';
  let row = 4;
  if (coupon) {
    nodes.push(step('coupon', 2, row++, 'action', 'generate_coupon', { ...couponDefaults(), ...coupon }));
    edges.push({ id: 'e-still-valid-coupon', source: last, target: 'coupon', sourceHandle: 'then' });
    last = 'coupon';
  }
  nodes.push(step('email', 2, row, 'action', 'send_email', email));
  edges.push({ id: `e-${last}-email`, source: last, target: 'email', ...(coupon ? {} : { sourceHandle: 'then' }) });

  return { nodes, edges };
}

const hasCrossSells = rule('order.has_cross_sells', 'is_true');

const greeting = __('Hi {{ customer.first_name }},', 'yaymatik');
const signOff = __('See you soon,\n{{ site.name }}', 'yaymatik');
const couponLines = __(
  'Use this coupon for {{ coupon.amount }} off your next order:\n\nCoupon code: {{ coupon.code }}\nExpires: {{ coupon.expiry_date }}',
  'yaymatik',
);

const email = (subject: string, heading: string, lines: string[]): SendEmailConfig => ({
  to: '{{ customer.email }}',
  subject,
  heading,
  message: [greeting, ...lines, signOff].join('\n\n'),
});

const crossSellEmail = (extra: string[] = []) =>
  email(__('Picked for you, {{ customer.first_name }}', 'yaymatik'), __('You might also like', 'yaymatik'), [
    __('Thanks again for your order {{ order.number }}. These go well with what you bought:', 'yaymatik'),
    '{{ order.cross_sell_products }}',
    ...extra,
  ]);

const shared = { category: 'post_purchase', popularity: 0, createdAt: '2026-10-07' } as const;

export const crossSellFirstTime = defineTemplate({
  ...shared,
  id: 'cross-sell-first-time-buyers',
  name: __('Cross-sell to first-time buyers', 'yaymatik'),
  description: __(
    '7 days after a first order is completed, suggest products that go well with what the customer bought.',
    'yaymatik',
  ),
  graph: graph({
    audience: [rule('customer.paid_order_count', 'equal', '1')],
    suggestions: hasCrossSells,
    days: 7,
    email: crossSellEmail(),
  }),
});

export const crossSellRepeat = defineTemplate({
  ...shared,
  id: 'cross-sell-repeat-buyers',
  name: __('Cross-sell to repeat buyers', 'yaymatik'),
  description: __(
    '7 days after a returning customer’s order is completed, suggest products that go well with what they bought.',
    'yaymatik',
  ),
  graph: graph({
    audience: [rule('customer.paid_order_count', 'greater_than', '1')],
    suggestions: hasCrossSells,
    days: 7,
    email: crossSellEmail(),
  }),
});

export const crossSellCoupon = defineTemplate({
  ...shared,
  id: 'cross-sell-coupon',
  name: __('Cross-sell with a coupon', 'yaymatik'),
  description: __(
    '14 days after an order is completed, suggest products that go well with it, with a 10% off coupon valid for 14 days.',
    'yaymatik',
  ),
  graph: graph({
    suggestions: hasCrossSells,
    days: 14,
    coupon: { amount: '10', expiry_days: '14' },
    email: crossSellEmail([couponLines]),
  }),
});

export const upsellAfter30Days = defineTemplate({
  ...shared,
  id: 'upsell-after-30-days',
  name: __('Upsell after 30 days', 'yaymatik'),
  description: __(
    '30 days after an order is completed, suggest upgrades to what the customer bought, with a 15% off coupon valid for 14 days.',
    'yaymatik',
  ),
  graph: graph({
    suggestions: rule('order.has_upsells', 'is_true'),
    days: 30,
    coupon: { amount: '15', expiry_days: '14' },
    email: email(
      __('Ready for an upgrade, {{ customer.first_name }}?', 'yaymatik'),
      __('Upgrade what you love', 'yaymatik'),
      [
        __(
          'It’s been a month since your order {{ order.number }}. Here are a few upgrades you might like:',
          'yaymatik',
        ),
        '{{ order.upsell_products }}',
        couponLines,
      ],
    ),
  }),
});
