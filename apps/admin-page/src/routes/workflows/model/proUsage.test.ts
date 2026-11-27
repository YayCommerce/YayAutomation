import type { WorkflowGraph, WorkflowNode } from '@yaymatik/types';
import { describe, expect, it } from 'vitest';

import { needsPro } from './proUsage';
import { validateWorkflow } from './validation';

/** True only in the free edition's run: while YayMatik Pro is active nothing is missing. */
const lite = process.env.YAYMATIK_EDITION === 'lite';

const node = (id: string, kind: WorkflowNode['kind'], block: string, config: Record<string, unknown> = {}) =>
  ({ id, kind, position: { x: 0, y: 0 }, data: { block, config } }) as WorkflowNode;
const rule = (field: string, value = '1', valueType = 'value') => ({
  field,
  operator: 'greater_than',
  value,
  valueType,
});
const condition = (rules: unknown[]) => node('c', 'condition', 'condition', { match: 'all', rules });

describe('needsPro, as PHP ProUsage::node()', () => {
  it('names each Pro part, and nothing else', () => {
    expect(needsPro(node('t', 'trigger', 'customer_inactive', { days: 90 }))).toBe(lite);
    expect(needsPro(condition([rule('customer.total_spent')]))).toBe(lite);
    expect(needsPro(condition([{ match: 'any', rules: [rule('order.total')] }]))).toBe(lite);
    expect(needsPro(condition([rule('order.total', 'order.subtotal', 'variable')]))).toBe(lite);
    expect(needsPro(node('n', 'action', 'add_order_note', { note: '{{ order.customer.total_spent }}' }))).toBe(lite);

    expect(needsPro(condition([rule('order.total')]))).toBe(false);
    expect(needsPro(node('n', 'action', 'add_order_note', { note: 'Hi {{ customer.first_name }}' }))).toBe(false);
  });

  it('blocks Activate with needs_pro on that step, and no other issue there', () => {
    const graph: WorkflowGraph = {
      nodes: [
        node('t', 'trigger', 'order_created'),
        node('n', 'action', 'add_order_note', { note: 'Spent {{ customer.total_spent }}', type: 'private' }),
      ],
      edges: [{ id: 't-n', source: 't', target: 'n' }],
    };
    const issues = validateWorkflow({ name: 'Spend', graph }).filter((issue) => issue.nodeId === 'n');

    expect(issues.map((issue) => issue.code)).toEqual(lite ? ['needs_pro'] : []);
  });
});
