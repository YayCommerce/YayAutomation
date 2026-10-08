import type { WorkflowGraph } from '@yay-automation/types';
import { describe, expect, it } from 'vitest';

import highValueOrderAlert from '../templates/high-value-order-alert';
import { structureErrors } from './structure';

const base = (): WorkflowGraph => structuredClone(highValueOrderAlert.graph);

describe('structureErrors', () => {
  it('accepts a valid tree', () => {
    expect(structureErrors(base())).toEqual([]);
  });

  it('rejects an unknown block', () => {
    const graph = base();
    graph.nodes[2].data.block = 'nope' as never;
    expect(structureErrors(graph)).toEqual(['Step "email": unknown block "nope"']);
  });

  it('rejects a block of the wrong kind', () => {
    const graph = base();
    graph.nodes[2].data.block = 'order_created';
    expect(structureErrors(graph)).toEqual(['Step "email": block "order_created" isn\'t a action']);
  });

  it('rejects a condition without its block', () => {
    const graph = base();
    graph.nodes[1].data.block = null;
    expect(structureErrors(graph)).toEqual(['Step "condition": a condition needs its block']);
  });

  it('needs exactly one trigger', () => {
    const graph = base();
    graph.nodes.push({ ...graph.nodes[0], id: 'trigger2' });
    expect(structureErrors(graph)).toContain('Needs exactly one trigger (found 2)');
  });

  it('rejects a duplicate step id', () => {
    const graph = base();
    graph.nodes[2].id = 'condition';
    expect(structureErrors(graph)[0]).toBe('Invalid or duplicate step id "condition"');
  });

  it('rejects a condition edge without a branch, and a second parent', () => {
    const graph = base();
    graph.edges[1].sourceHandle = undefined;
    graph.edges.push({ id: 'e-trigger-email', source: 'trigger', target: 'email' });
    expect(structureErrors(graph)).toEqual([
      'Invalid connection "e-condition-email" (condition → email)',
      'Invalid connection "e-trigger-email" (trigger → email)',
    ]);
  });
});
