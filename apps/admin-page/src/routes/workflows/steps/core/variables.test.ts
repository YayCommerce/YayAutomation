import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { WorkflowGraph } from '@yaymatik/types';
import { describe, expect, it } from 'vitest';

import { steps } from '..';
import { availableVariables, canInsert, groupVariables } from './variables';

/** Shared with PHP `ValidatorTest::test_variables_a_step_can_use`. */
const { cases } = JSON.parse(
  readFileSync(resolve(__dirname, '../../../../../../../tests/fixtures/variables.json'), 'utf8'),
) as { cases: { name: string; graph: WorkflowGraph; nodeId: string; keys: string[] }[] };

describe('availableVariables', () => {
  it.each(cases.map((c) => [c.name, c] as const))('%s', (_name, { graph, nodeId, keys }) => {
    const variables = availableVariables(graph, nodeId).filter((variable) => !variable.comingSoon);
    expect(variables.map((variable) => variable.key)).toEqual(keys);
  });

  it('carries label and type, and lists fields PHP lacks as coming soon', () => {
    const { graph } = cases.find((c) => c.nodeId === 'status')!;
    const variables = availableVariables(graph, 'status');
    expect(variables).toContainEqual({ key: 'order.total', label: 'Order total', type: 'money', comingSoon: false });
    expect(variables).toContainEqual({ key: 'steps.c.result', label: 'Result', type: 'enum', comingSoon: false });
    expect(variables.find((variable) => variable.key === 'order.has_upsells')?.comingSoon).toBe(true);
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

describe('canInsert', () => {
  const { graph } = cases.find((c) => c.nodeId === 'status')!;
  const variables = availableVariables(graph, 'status');
  const comingSoon = variables.find((variable) => variable.comingSoon)!;
  const built = variables.find((variable) => !variable.comingSoon)!;

  it('lets a built step insert built fields only: PHP rejects coming-soon ones on Activate', () => {
    expect(steps.add_order_note.comingSoon).toBe(false);
    expect(canInsert(built, steps.add_order_note.comingSoon)).toBe(true);
    expect(canInsert(comingSoon, steps.add_order_note.comingSoon)).toBe(false);
  });

  it('lets a coming-soon step insert coming-soon fields too', () => {
    for (const id of ['send_email'] as const) {
      expect(steps[id].comingSoon).toBe(true);
      expect(canInsert(comingSoon, steps[id].comingSoon)).toBe(true);
    }
  });
});
