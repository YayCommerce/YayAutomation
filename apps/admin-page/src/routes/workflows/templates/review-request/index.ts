import type { StepId, WorkflowEdge, WorkflowGraph, WorkflowNode } from '@yaymatik/types';
import { __ } from '@wordpress/i18n';

import { BRANCH_OFFSET, ROW_GAP } from '../../model/graph';
import type { SendEmailConfig } from '../../steps/actions/send-email/schema';
import type { WaitConfig } from '../../steps/actions/wait/schema';
import { defineTemplate } from '../core/defineTemplate';
import { customerEmail, rule } from '../core/flow';

const hasProducts = rule('order.has_products_not_reviewed', 'is_true');
const stillValid = {
  match: 'all',
  rules: [rule('order.status', 'is', 'completed'), hasProducts, rule('customer.unsubscribed', 'is_false')],
};
const sevenDays = { delay: { value: 7, unit: 'days' } } satisfies WaitConfig;

const first = customerEmail(__('How did your order go?', 'yaymatik'), __('Tell us what you think', 'yaymatik'), [
  __(
    'Thanks again for order {{ order.number }}. If you have a minute, tell other shoppers what you think:',
    'yaymatik',
  ),
  '{{ order.review_products }}',
  __('It takes a minute and helps a lot.', 'yaymatik'),
]);

const second = customerEmail(__('A quick review?', 'yaymatik'), __('Still have a minute?', 'yaymatik'), [
  __('You haven’t reviewed these yet:', 'yaymatik'),
  '{{ order.review_products }}',
]);

/**
 * Check there is something to review, wait, then check again with fresh data (still completed, still
 * something to review, not unsubscribed meanwhile), then email; the reminder repeats the wait and the check.
 */
function graph(reminder?: SendEmailConfig): WorkflowGraph {
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
    step('condition', 0, 1, 'condition', 'condition', { match: 'all', rules: [hasProducts] }),
    step('wait', 1, 2, 'action', 'wait', sevenDays),
    step('still-valid', 1, 3, 'condition', 'condition', stillValid),
    step('email', 2, 4, 'action', 'send_email', first),
  ];
  const edges: WorkflowEdge[] = [
    { id: 'e-trigger-condition', source: 'trigger', target: 'condition' },
    { id: 'e-condition-wait', source: 'condition', target: 'wait', sourceHandle: 'then' },
    { id: 'e-wait-still-valid', source: 'wait', target: 'still-valid' },
    { id: 'e-still-valid-email', source: 'still-valid', target: 'email', sourceHandle: 'then' },
  ];
  if (reminder) {
    nodes.push(
      step('wait-2', 2, 5, 'action', 'wait', sevenDays),
      step('still-valid-2', 2, 6, 'condition', 'condition', stillValid),
      step('email-2', 3, 7, 'action', 'send_email', reminder),
    );
    edges.push(
      { id: 'e-email-wait-2', source: 'email', target: 'wait-2' },
      { id: 'e-wait-2-still-valid-2', source: 'wait-2', target: 'still-valid-2' },
      { id: 'e-still-valid-2-email-2', source: 'still-valid-2', target: 'email-2', sourceHandle: 'then' },
    );
  }
  return { nodes, edges };
}

const shared = { category: 'post_purchase', workflow: 'review_request', createdAt: '2026-11-03' } as const;

export const reviewRequest = defineTemplate({
  ...shared,
  id: 'review-request',
  name: __('Review request', 'yaymatik'),
  description: __(
    '7 days after an order is completed, ask for a review of each product the customer hasn’t reviewed yet.',
    'yaymatik',
  ),
  graph: graph(),
});

export const reviewRequestReminder = defineTemplate({
  ...shared,
  id: 'review-request-reminder',
  name: __('Review request + reminder', 'yaymatik'),
  description: __(
    '7 days after an order is completed, ask for a review of each product; a week later, remind once about the ones still not reviewed.',
    'yaymatik',
  ),
  graph: graph(second),
});
