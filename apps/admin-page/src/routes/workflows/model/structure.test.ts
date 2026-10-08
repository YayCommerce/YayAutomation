import type { WorkflowGraph } from '@yay-automation/types';
import { describe, expect, it } from 'vitest';

import highValueOrderAlert from '../templates/high-value-order-alert';
import { structureErrors } from './structure';

const base = (): WorkflowGraph => structuredClone(highValueOrderAlert.graph);
/** Steps and edges by id, so editing the template doesn't change what a test covers. */
const node = (graph: WorkflowGraph, id: string) => graph.nodes.find((n) => n.id === id)!;

describe('structureErrors', () => {
  it('accepts a valid tree', () => {
    expect(structureErrors(base())).toEqual([]);
  });

  it('rejects an unknown step', () => {
    const graph = base();
    node(graph, 'email').data.block = 'nope' as never;
    expect(structureErrors(graph)).toEqual(['Step "email": unknown step "nope"']);
  });

  it('rejects a step of the wrong kind', () => {
    const graph = base();
    node(graph, 'email').data.block = 'order_created';
    expect(structureErrors(graph)).toEqual(['Step "email": step "order_created" isn\'t a action']);
  });

  it('rejects a condition without its step', () => {
    const graph = base();
    node(graph, 'condition').data.block = null;
    expect(structureErrors(graph)).toEqual(['Step "condition": a condition needs its step']);
  });

  it('needs exactly one trigger', () => {
    const graph = base();
    graph.nodes.push({ ...node(graph, 'trigger'), id: 'trigger2' });
    expect(structureErrors(graph)).toContain('Needs exactly one trigger (found 2)');
  });

  it('rejects a duplicate step id', () => {
    const graph = base();
    node(graph, 'email').id = 'condition';
    expect(structureErrors(graph)[0]).toBe('Invalid or duplicate step id "condition"');
  });

  it('rejects a condition edge without a branch, and a second parent', () => {
    const graph = base();
    graph.edges.find((e) => e.id === 'e-condition-email')!.sourceHandle = undefined;
    graph.edges.push({ id: 'e-trigger-email', source: 'trigger', target: 'email' });
    expect(structureErrors(graph)).toEqual([
      'Invalid connection "e-condition-email" (condition → email)',
      'Invalid connection "e-trigger-email" (trigger → email)',
    ]);
  });
});
