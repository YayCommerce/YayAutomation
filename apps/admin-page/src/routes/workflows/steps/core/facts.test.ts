import type { WorkflowGraph } from '@yaymatik/types';
import { afterEach, describe, expect, it, vi } from 'vitest';

import meta from '../../../../test/fixtures/meta.json';

/** Another plugin's trigger with a fact, as PHP's catalog sends it. */
const pointsTrigger = {
  kind: 'trigger',
  plugin: 'acme',
  provides: ['customer'],
  category: 'customer',
  label: 'Customer earned points',
  description: 'When a customer gets reward points.',
  icon: '',
  summary: '',
  onError: 'stop',
  fields: [],
  outputs: [],
  facts: [{ key: 'trigger.points', label: 'Points earned', type: 'number', valueType: 'number', conditional: true }],
};

async function load() {
  vi.resetModules();
  const automation = meta.automation;
  window.yayMatikMeta = {
    ...meta,
    automation: {
      ...automation,
      plugins: [...automation.plugins, { id: 'acme', label: 'Acme', description: '', active: true, logo: '' }],
      steps: { ...automation.steps, acme_points: pointsTrigger },
    },
  } as unknown as Window['yayMatikMeta'];
  return {
    ...(await import('./variables')),
    ...(await import('./data')),
    ...(await import('../../model/validation')),
  };
}

const graphOn = (trigger: string, note = '') =>
  ({
    nodes: [
      { id: 't', kind: 'trigger', position: { x: 0, y: 0 }, data: { block: trigger, config: {} } },
      { id: 'a', kind: 'action', position: { x: 0, y: 170 }, data: { block: 'add_order_note', config: { note } } },
    ],
    edges: [{ id: 'e1', source: 't', target: 'a' }],
  }) as unknown as WorkflowGraph;
const graph = graphOn('acme_points');

describe('trigger facts', () => {
  afterEach(() => {
    window.yayMatikMeta = meta as unknown as Window['yayMatikMeta'];
  });

  it('come first in Insert variable, pass the tag check and can be compared in a condition', async () => {
    const { availableVariables, tagError, fieldByKey } = await load();

    expect(availableVariables(graph, 'a')[0]).toEqual({
      key: 'trigger.points',
      label: 'Points earned',
      type: 'number',
      block: false,
    });
    expect(tagError(['You got {{ trigger.points }} points'])).toBeNull();
    expect(tagError(['{{ trigger.nope }}'])).not.toBeNull();
    expect(fieldByKey('trigger.points')).toMatchObject({ type: 'number', conditional: true });
  });

  it('are data of their own trigger only, in the editor check as on the server', async () => {
    const { validateWorkflow, itemLabel } = await load();
    const codes = (trigger: string) =>
      validateWorkflow({ name: 'A', graph: graphOn(trigger, 'You got {{ trigger.points }} points') })
        .filter((issue) => issue.nodeId === 'a')
        .map((issue) => issue.code);

    expect(codes('acme_points')).not.toContain('invalid_config');
    expect(codes('order_status_changed')).toContain('invalid_config');
    expect(itemLabel('trigger')).toBe('Trigger');
  });
});
