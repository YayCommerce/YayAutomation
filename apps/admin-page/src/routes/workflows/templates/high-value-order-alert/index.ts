import { __ } from '@wordpress/i18n';

import { BRANCH_OFFSET, ROW_GAP } from '../../model/graph';
import { defineTemplate } from '../core/defineTemplate';

/** Email the store team when a big order starts processing. */
export default defineTemplate({
  id: 'high-value-order-alert',
  name: __('High-value order alert', 'yaymatik'),
  description: __(
    'Email the store team when an order of an amount you choose or more starts processing, so big orders get attention fast.',
    'yaymatik',
  ),
  category: 'alerts',
  workflow: 'high_value_order_alert',
  createdAt: '2026-09-28',
  graph: {
    nodes: [
      {
        id: 'trigger',
        kind: 'trigger',
        position: { x: 0, y: 0 },
        data: {
          block: 'order_status_changed',
          config: { from: ['pending', 'on-hold', 'failed'], to: ['processing'], only_checkout: false },
        },
      },
      {
        id: 'condition',
        kind: 'condition',
        position: { x: 0, y: ROW_GAP },
        data: {
          block: 'condition',
          // Ships valid, so the draft has no issues; the user can adjust the amount.
          config: { match: 'all', rules: [{ field: 'order.total', operator: 'greater_or_equal', value: '500' }] },
        },
      },
      {
        id: 'email',
        kind: 'action',
        position: { x: -BRANCH_OFFSET, y: ROW_GAP * 2 },
        data: {
          block: 'send_internal_email',
          config: {
            // Blank: the site admin email.
            recipients: '',
            subject: __('High-value order {{ order.number }} - {{ order.total }}', 'yaymatik'),
            message: __(
              'A high-value order was placed on {{ site.name }}.\n\nOrder: {{ order.number }}\nTotal: {{ order.total }}\nCustomer: {{ customer.first_name }} {{ customer.last_name }} ({{ customer.email }})\n\nItems:\n{{ order.items }}',
              'yaymatik',
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
