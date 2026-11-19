import { __ } from '@wordpress/i18n';

import { BRANCH_OFFSET, ROW_GAP } from '@/routes/workflows/model/graph';
import { defineTemplate } from '@/routes/workflows/templates/core/defineTemplate';
import { customerEmail, rule, waitStep } from '@/routes/workflows/templates/core/flow';

const wait = waitStep('wait', 1, 'hours');

/** A customer logs in and, an hour later, has added nothing to the cart since: a nudge back to the shop. */
export const loginNoCart = defineTemplate({
  category: 'customer_lifecycle',
  workflow: 'login_no_cart',
  createdAt: '2026-11-18',
  id: 'login-no-cart',
  name: __('Logged in, nothing added to cart', 'yaymatik'),
  description: __(
    'When a customer logs in and adds nothing to the cart within an hour, send a reminder with a link to the shop. Once a week at most.',
    'yaymatik',
  ),
  graph: {
    nodes: [
      {
        id: 'trigger',
        kind: 'trigger',
        position: { x: 0, y: 0 },
        data: { block: 'customer_logged_in', config: { when: ['sign_in', 'return'], days: 7 } },
      },
      {
        id: wait.id,
        kind: 'action',
        position: { x: 0, y: ROW_GAP },
        data: { block: wait.stepId, config: wait.config },
      },
      {
        id: 'condition',
        kind: 'condition',
        position: { x: 0, y: ROW_GAP * 2 },
        data: {
          block: 'condition',
          // Added before the login (or never): nothing added since. Same-day adds compare by time.
          config: {
            match: 'any',
            rules: [
              rule('customer.last_added_to_cart', 'is_not_set'),
              { ...rule('customer.last_added_to_cart', 'before', 'trigger.time'), valueType: 'variable' },
            ],
          },
        },
      },
      {
        id: 'email',
        kind: 'action',
        position: { x: -BRANCH_OFFSET, y: ROW_GAP * 3 },
        data: {
          block: 'send_email',
          config: customerEmail(
            __('Still looking around, {{ customer.first_name | default: "there" }}?', 'yaymatik'),
            __('Good to see you again', 'yaymatik'),
            [
              __('Thanks for stopping by {{ site.name }}. Your favorites are a click away:', 'yaymatik'),
              '{{ site.shop_button }}',
            ],
          ),
        },
      },
    ],
    edges: [
      { id: 'e-trigger-wait', source: 'trigger', target: 'wait' },
      { id: 'e-wait-condition', source: 'wait', target: 'condition' },
      { id: 'e-condition-email', source: 'condition', target: 'email', sourceHandle: 'then' },
    ],
  },
});
