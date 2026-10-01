import { __ } from '@wordpress/i18n';

import { BRANCH_OFFSET, ROW_GAP } from '../../model/graph';
import { defineTemplate } from '../core/defineTemplate';

/** Email the store team when a big order moves to Processing. */
export default defineTemplate({
  id: 'high-value-order-alert',
  name: __('High-value order alert', 'yay-automation'),
  description: __(
    'Email the store team when an order above an amount you choose moves to Processing, so big orders get attention fast.',
    'yay-automation',
  ),
  category: __('Order management', 'yay-automation'),
  apps: ['woocommerce', 'email'],
  popularity: 0,
  createdAt: '2026-09-28',
  graph: {
    nodes: [
      {
        id: 'trigger',
        kind: 'trigger',
        position: { x: 0, y: 0 },
        data: {
          title: __('Order moves to Processing', 'yay-automation'),
          block: 'order_status_changed',
          config: { from: [], to: ['processing'] },
        },
      },
      {
        id: 'condition',
        kind: 'condition',
        position: { x: 0, y: ROW_GAP },
        data: {
          title: __('Order total is above your threshold', 'yay-automation'),
          block: 'order_total',
          // Ships valid, so the draft has no issues; the user can adjust the amount.
          config: { operator: 'gt', amount: 500 },
        },
      },
      {
        id: 'email',
        kind: 'action',
        position: { x: -BRANCH_OFFSET, y: ROW_GAP * 2 },
        data: {
          title: __('Email the store admin', 'yay-automation'),
          block: 'send_admin_email',
          config: {
            recipients: '',
            subject: __('High-value order #{{order_number}} ({{order_total}})', 'yay-automation'),
            body: __(
              'A high-value order just came in.\n\nOrder: #{{order_number}}\nTotal: {{order_total}}\nCustomer: {{customer_name}} <{{customer_email}}>\n\nView order: {{order_admin_url}}\n\n— {{site_name}}',
              'yay-automation',
            ),
          },
        },
      },
    ],
    edges: [
      { id: 'e-trigger-condition', source: 'trigger', target: 'condition' },
      { id: 'e-condition-email', source: 'condition', target: 'email', sourceHandle: 'then' },
    ],
  },
});
