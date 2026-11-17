import type { WorkflowGraph } from '@yaymatik/types';
import { afterEach, describe, expect, it, vi } from 'vitest';

import meta from '../../../../test/fixtures/meta.json';

async function load() {
  vi.resetModules();
  window.yayMatikMeta = {
    ...meta,
    automation: {
      ...meta.automation,
      inactiveSteps: {
        acme_add_points: {
          module: 'acme',
          plugin: 'Acme Rewards',
          kind: 'action',
          label: 'Add reward points',
          outputs: [{ key: 'balance', label: 'New balance' }],
          facts: [],
        },
      },
    },
  } as unknown as Window['yayMatikMeta'];
  return {
    ...(await import('..')),
    ...(await import('../../model/structure')),
    ...(await import('../../model/validation')),
  };
}

const graph = {
  nodes: [
    { id: 't', kind: 'trigger', position: { x: 0, y: 0 }, data: { block: 'order_created', config: {} } },
    { id: 'a', kind: 'action', position: { x: 0, y: 170 }, data: { block: 'acme_add_points', config: { points: 10 } } },
    {
      id: 'n',
      kind: 'action',
      position: { x: 0, y: 340 },
      data: { block: 'add_order_note', config: { note: 'Balance {{ steps.a.balance }}' } },
    },
  ],
  edges: [
    { id: 'e1', source: 't', target: 'a' },
    { id: 'e2', source: 'a', target: 'n' },
  ],
} as unknown as WorkflowGraph;

describe('a step of a plugin that is off', () => {
  afterEach(() => {
    window.yayMatikMeta = meta as unknown as Window['yayMatikMeta'];
  });

  it('is drawn greyed with its name, keeps the graph valid, and blocks only Activate', async () => {
    const { stepOf, structureErrors, validateWorkflow } = await load();
    const stepDef = stepOf(graph.nodes[1].data)!;

    expect(stepDef).toMatchObject({ label: 'Add reward points', inactive: 'Acme Rewards' });
    expect(stepDef.summary({})).toBe('Needs Acme Rewards — the plugin isn’t active.');
    expect(structureErrors(graph)).toEqual([]);
    expect(validateWorkflow({ name: 'Points', graph })).toEqual([
      {
        code: 'missing_plugin',
        message: 'This workflow uses Acme Rewards, which isn’t active. Activate Acme Rewards to turn the workflow on.',
        nodeId: 'a',
      },
    ]);
  });
});
