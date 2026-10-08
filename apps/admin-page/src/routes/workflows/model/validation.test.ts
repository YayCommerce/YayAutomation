import type { WorkflowGraph } from '@yay-automation/types';
import { describe, expect, it } from 'vitest';

import highValueOrderAlert from '../templates/high-value-order-alert';
import { addNode, removeNode } from './graph';
import { availableData, validateWorkflow } from './validation';

const base = (): WorkflowGraph => structuredClone(highValueOrderAlert.graph);
/** Steps and edges by id, so editing the template doesn't change what a test covers. */
const node = (graph: WorkflowGraph, id: string) => graph.nodes.find((n) => n.id === id)!;
const codes = (graph: WorkflowGraph, name = 'Alert') => validateWorkflow({ name, graph }).map((issue) => issue.code);

describe('validateWorkflow', () => {
  it('finds nothing in a complete workflow', () => {
    expect(validateWorkflow({ name: 'Alert', graph: base() })).toEqual([]);
  });

  it('needs a name, ignoring tags', () => {
    expect(codes(base(), ' <b></b> ')).toEqual(['name_required']);
  });

  it('needs a chosen trigger', () => {
    const graph = base();
    node(graph, 'trigger').data.block = null;
    expect(codes(graph)).toEqual(['no_trigger']);
  });

  it('needs an action', () => {
    expect(codes(removeNode(base(), 'email'))).toEqual(['no_action']);
  });

  it('lists unlinked steps in one workflow-level issue', () => {
    const issues = validateWorkflow({ name: 'Alert', graph: removeNode(base(), 'condition') });
    expect(issues).toEqual([expect.objectContaining({ code: 'unlinked', nodeIds: ['email'] })]);
  });

  it('flags an action with no step chosen', () => {
    const graph = addNode(base(), 'email', 'action', undefined, 'next');
    expect(validateWorkflow({ name: 'Alert', graph })).toEqual([
      expect.objectContaining({ code: 'no_block', nodeId: 'next' }),
    ]);
  });

  it('flags invalid settings on the step', () => {
    const graph = base();
    node(graph, 'email').data.config = { ...node(graph, 'email').data.config, subject: '' };
    expect(validateWorkflow({ name: 'Alert', graph })).toEqual([
      expect.objectContaining({ code: 'invalid_config', nodeId: 'email' }),
    ]);
  });
});

describe('availableData', () => {
  it('is null until a trigger is chosen', () => {
    const graph = base();
    node(graph, 'trigger').data.block = null;
    expect(availableData(graph)).toBeNull();
  });

  it("gives every step the trigger's data plus the always-available items", () => {
    const at = availableData(base())!;
    expect(at('email')).toEqual(expect.arrayContaining(['order', 'customer', 'site']));
  });
});
