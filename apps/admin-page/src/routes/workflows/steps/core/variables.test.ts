import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { WorkflowGraph } from '@yay-automation/types';
import { describe, expect, it } from 'vitest';

import { availableVariables } from './variables';

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
