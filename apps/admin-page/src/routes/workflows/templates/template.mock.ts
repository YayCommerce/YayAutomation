import type { WorkflowBranch, WorkflowEdge, WorkflowNode, WorkflowTemplate } from '@yay-automation/types';

import { BRANCH_OFFSET, ROW_GAP } from '../editor/workflow.graph';
import { delay } from '../editor/workflow.mock';

/**
 * In-memory template library. Same async shape a REST endpoint would have, so swapping it
 * out only touches `template.query.ts`. Triggers and actions are display-only for now.
 */

function node(id: string, kind: WorkflowNode['kind'], row: number, title: string, col = 0): WorkflowNode {
  return { id, kind, position: { x: col * BRANCH_OFFSET, y: row * ROW_GAP }, data: { title } };
}

function edge(source: string, target: string, sourceHandle?: WorkflowBranch): WorkflowEdge {
  return { id: `e-${source}-${target}`, source, target, sourceHandle };
}

const templates: WorkflowTemplate[] = [
  {
    id: 'recover-abandoned-cart',
    name: 'Recover abandoned carts',
    description:
      'Email customers who added products to their cart but left without checking out, with a link back to their cart.',
    category: 'Promotions',
    apps: ['woocommerce', 'automation', 'email'],
    popularity: 1840,
    createdAt: '2026-03-02',
    graph: {
      nodes: [
        node('trigger', 'trigger', 0, 'Cart abandoned'),
        node('wait', 'action', 1, 'Wait 4 hours'),
        node('condition', 'condition', 2, 'Order not placed'),
        node('email', 'action', 3, 'Send cart reminder email', -1),
      ],
      edges: [edge('trigger', 'wait'), edge('wait', 'condition'), edge('condition', 'email', 'then')],
    },
  },
  {
    id: 'thank-first-order',
    name: 'Thank customers after their first order',
    description:
      'Send a personal thank-you after a customer’s first order. Returning customers get a loyalty note instead.',
    category: 'Customer loyalty',
    apps: ['woocommerce', 'email'],
    popularity: 1320,
    createdAt: '2026-01-18',
    graph: {
      nodes: [
        node('trigger', 'trigger', 0, 'Order completed'),
        node('condition', 'condition', 1, 'Customer’s first order'),
        node('thanks', 'action', 2, 'Send thank-you email', -1),
        node('loyalty', 'action', 2, 'Send loyalty email', 1),
      ],
      edges: [
        edge('trigger', 'condition'),
        edge('condition', 'thanks', 'then'),
        edge('condition', 'loyalty', 'otherwise'),
      ],
    },
  },
  {
    id: 'tag-vip-customers',
    name: 'Tag VIP customers',
    description: 'Tag customers whose lifetime spend passes $500 and welcome them to your VIP perks.',
    category: 'Customer loyalty',
    apps: ['woocommerce', 'automation', 'email'],
    popularity: 960,
    createdAt: '2026-05-10',
    graph: {
      nodes: [
        node('trigger', 'trigger', 0, 'Order paid'),
        node('condition', 'condition', 1, 'Lifetime spend > $500'),
        node('tag', 'action', 2, 'Add customer tag “VIP”', -1),
        node('email', 'action', 3, 'Send VIP welcome email', -1),
      ],
      edges: [edge('trigger', 'condition'), edge('condition', 'tag', 'then'), edge('tag', 'email')],
    },
  },
  {
    id: 'low-stock-alert',
    name: 'Alert the team about low stock',
    description: 'Email the store admin when a product’s stock drops below 5, so you can reorder before it sells out.',
    category: 'Inventory',
    apps: ['woocommerce', 'email'],
    popularity: 1510,
    createdAt: '2025-11-04',
    graph: {
      nodes: [
        node('trigger', 'trigger', 0, 'Product stock changed'),
        node('condition', 'condition', 1, 'Stock quantity < 5'),
        node('email', 'action', 2, 'Email store admin', -1),
      ],
      edges: [edge('trigger', 'condition'), edge('condition', 'email', 'then')],
    },
  },
  {
    id: 'request-product-review',
    name: 'Request a product review',
    description: 'Ask customers to review what they bought 7 days after their order is completed.',
    category: 'Reviews',
    apps: ['woocommerce', 'automation', 'email'],
    popularity: 1105,
    createdAt: '2026-02-21',
    graph: {
      nodes: [
        node('trigger', 'trigger', 0, 'Order completed'),
        node('wait', 'action', 1, 'Wait 7 days'),
        node('email', 'action', 2, 'Send review request email'),
      ],
      edges: [edge('trigger', 'wait'), edge('wait', 'email')],
    },
  },
  {
    id: 'win-back-customers',
    name: 'Win back inactive customers',
    description: 'Send a 15% coupon to customers who haven’t ordered in the last 60 days.',
    category: 'Promotions',
    apps: ['woocommerce', 'automation', 'email'],
    popularity: 870,
    createdAt: '2026-06-30',
    graph: {
      nodes: [
        node('trigger', 'trigger', 0, 'Every day at 9:00'),
        node('condition', 'condition', 1, 'No order in 60 days'),
        node('coupon', 'action', 2, 'Create 15% coupon', -1),
        node('email', 'action', 3, 'Send win-back email', -1),
      ],
      edges: [edge('trigger', 'condition'), edge('condition', 'coupon', 'then'), edge('coupon', 'email')],
    },
  },
  {
    id: 'hold-high-value-orders',
    name: 'Hold high-value orders for review',
    description: 'Put orders over $1,000 from first-time customers on hold and notify the team to check them.',
    category: 'Order management',
    apps: ['woocommerce', 'email'],
    popularity: 640,
    createdAt: '2026-08-12',
    graph: {
      nodes: [
        node('trigger', 'trigger', 0, 'Order created'),
        node('condition', 'condition', 1, 'Total > $1,000 and first order'),
        node('hold', 'action', 2, 'Set order status “On hold”', -1),
        node('email', 'action', 3, 'Email store admin', -1),
      ],
      edges: [edge('trigger', 'condition'), edge('condition', 'hold', 'then'), edge('hold', 'email')],
    },
  },
  {
    id: 'upsell-after-first-purchase',
    name: 'Upsell after the first purchase',
    description: 'Show featured products 14 days after a customer’s first purchase if they haven’t ordered again.',
    category: 'Promotions',
    apps: ['woocommerce', 'automation', 'email'],
    popularity: 720,
    createdAt: '2026-04-07',
    graph: {
      nodes: [
        node('trigger', 'trigger', 0, 'Order completed'),
        node('wait', 'action', 1, 'Wait 14 days'),
        node('condition', 'condition', 2, 'No second order'),
        node('email', 'action', 3, 'Send featured products email', -1),
      ],
      edges: [edge('trigger', 'wait'), edge('wait', 'condition'), edge('condition', 'email', 'then')],
    },
  },
];

export async function getTemplates(): Promise<WorkflowTemplate[]> {
  await delay(300);
  return structuredClone(templates);
}

export async function getTemplate(id: string): Promise<WorkflowTemplate> {
  await delay(300);
  const template = templates.find((t) => t.id === id);
  if (!template) {
    throw new Error(`Template "${id}" not found`);
  }
  return structuredClone(template);
}
