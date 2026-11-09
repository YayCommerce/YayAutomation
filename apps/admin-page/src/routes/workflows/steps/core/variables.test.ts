import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { StepId, WorkflowGraph } from '@yaymatik/types';
import { describe, expect, it } from 'vitest';
import { sprintf } from '@wordpress/i18n';

import { messages } from '../../model/messages';
import { tagError } from './data';
import { availableVariables, groupVariables, ruleOnlyVariables } from './variables';

/** Shared with PHP `ValidatorTest::test_variables_a_step_can_use`. */
const { cases } = JSON.parse(
  readFileSync(resolve(__dirname, '../../../../../../../tests/fixtures/variables.json'), 'utf8'),
) as { cases: { name: string; graph: WorkflowGraph; nodeId: string; keys: string[] }[] };

describe('availableVariables', () => {
  it.each(cases.map((c) => [c.name, c] as const))('%s', (_name, { graph, nodeId, keys }) => {
    expect(availableVariables(graph, nodeId).map((variable) => variable.key)).toEqual(keys);
  });

  it('carries label and type', () => {
    const { graph } = cases.find((c) => c.nodeId === 'status')!;
    const variables = availableVariables(graph, 'status');
    expect(variables).toContainEqual({
      key: 'order.total',
      label: 'Order total',
      type: 'money',
      block: false,
    });
    expect(variables).toContainEqual({
      key: 'steps.c.result',
      label: 'Result',
      type: 'enum',
      block: false,
    });
  });

  it('flags block data fields and block outputs, e.g. the product grids and the coupon box of a Generate coupon above', () => {
    const node = (id: string, kind: 'trigger' | 'action', block: StepId) => ({
      id,
      kind,
      position: { x: 0, y: 0 },
      data: { block, config: {} },
    });
    const graph: WorkflowGraph = {
      nodes: [
        node('t', 'trigger', 'order_created'),
        node('c', 'action', 'generate_coupon'),
        node('m', 'action', 'send_email'),
      ],
      edges: [
        { id: 't-c', source: 't', target: 'c' },
        { id: 'c-m', source: 'c', target: 'm' },
      ],
    };
    const variables = availableVariables(graph, 'm');
    expect(variables.filter((variable) => variable.block).map((variable) => variable.key)).toEqual([
      'order.cross_sell_products',
      'order.upsell_products',
      'order.review_products',
      'site.welcome_links',
      'site.shop_button',
      'site.account_button',
      'site.new_products',
      'steps.c.box',
    ]);
    expect(variables.find((variable) => variable.key === 'steps.c.code')?.block).toBe(false);
    expect(variables.find((variable) => variable.key === 'order.has_cross_sells')?.block).toBe(false);
  });
});

describe('custom fields', () => {
  it('are not offered by Insert variable, and are not a known tag', () => {
    const keys = availableVariables(
      {
        nodes: [
          {
            id: 't',
            kind: 'trigger',
            position: { x: 0, y: 0 },
            data: { block: 'order_created', config: {} },
          },
        ],
        edges: [],
      },
      't',
    ).map((variable) => variable.key);
    expect(keys).not.toContain('order.meta');
    expect(keys).not.toContain('customer.meta');
    expect(keys).toContain('order.total');
    expect(tagError(['{{ order.meta }}'])).toBe(sprintf(messages.unknownVariable, '{{ order.meta }}'));
  });
});

describe('ruleOnlyVariables', () => {
  const variable = (key: string) => ({ key, label: key, type: 'string', block: false });

  it('offers the custom fields of the items a step has, for rules', () => {
    const keys = ruleOnlyVariables([variable('order.total'), variable('customer.email')]).map((v) => v.key);
    expect(keys).toEqual(['order.meta', 'customer.meta']);
  });

  it('none for an item the step does not have', () => {
    expect(ruleOnlyVariables([variable('customer.email')]).map((v) => v.key)).toEqual(['customer.meta']);
    expect(ruleOnlyVariables([variable('site.name')])).toEqual([]);
  });
});

describe('groupVariables', () => {
  it('groups by data item, relations as labelled sections, then one group per step above', () => {
    const { graph } = cases.find((c) => c.nodeId === 'status')!;
    const groups = groupVariables(availableVariables(graph, 'status'));
    const order = groups.find((g) => g.id === 'order')!;

    expect(order.source).toBe('data');
    expect(order.sections.map((s) => [s.path, s.label])).toEqual([
      ['order', null],
      ['order.customer', 'Customer'],
    ]);
    expect(order.sections[1].variables[0].key).toMatch(/^order\.customer\./);
    expect(groups.find((g) => g.id === 'c')).toEqual({
      id: 'c',
      source: 'step',
      sections: [{ path: 'steps.c', label: null, variables: [expect.objectContaining({ key: 'steps.c.result' })] }],
    });
    // Data first, steps after.
    expect(groups.findIndex((g) => g.source === 'step')).toBeGreaterThan(groups.findIndex((g) => g.id === 'site'));
  });
});
