import type { WorkflowGraph } from '@yay-automation/types';
import { describe, expect, it } from 'vitest';

import highValueOrderAlert from '../templates/high-value-order-alert';
import {
  addNode,
  BRANCH_OFFSET,
  descendantsOf,
  hasChild,
  moveNode,
  removeNode,
  ROW_GAP,
  setNodeStep,
  updateNodeConfig,
} from './graph';

const base = (): WorkflowGraph => structuredClone(highValueOrderAlert.graph);

describe('addNode', () => {
  it('appends an action below its parent and links it', () => {
    const graph = addNode(base(), 'email', 'action', undefined, 'next');
    const email = graph.nodes.find((node) => node.id === 'email')!;
    const next = graph.nodes.find((node) => node.id === 'next')!;
    expect(next.kind).toBe('action');
    expect(next.position).toEqual({ x: email.position.x, y: email.position.y + ROW_GAP });
    expect(next.data.block).toBeNull();
    expect(graph.edges).toContainEqual({
      id: 'e-email-next',
      source: 'email',
      target: 'next',
      sourceHandle: undefined,
    });
  });

  it('offsets a condition branch to its side', () => {
    const graph = addNode(base(), 'condition', 'action', 'otherwise', 'other');
    const condition = graph.nodes.find((node) => node.id === 'condition')!;
    const other = graph.nodes.find((node) => node.id === 'other')!;
    expect(other.position.x).toBe(condition.position.x + BRANCH_OFFSET);
    expect(graph.edges.at(-1)?.sourceHandle).toBe('otherwise');
  });

  it('returns the same graph for an unknown parent', () => {
    const graph = base();
    expect(addNode(graph, 'missing', 'action')).toBe(graph);
  });
});

describe('removeNode', () => {
  it('removes the step and its edges, leaving steps below unlinked', () => {
    const graph = removeNode(base(), 'condition');
    expect(graph.nodes.map((node) => node.id)).toEqual(['trigger', 'email']);
    expect(graph.edges).toEqual([]);
  });

  it('never removes the trigger', () => {
    const graph = base();
    expect(removeNode(graph, 'trigger')).toBe(graph);
  });
});

describe('graph queries and updates', () => {
  it('hasChild checks the handle', () => {
    const graph = base();
    expect(hasChild(graph, 'condition', 'then')).toBe(true);
    expect(hasChild(graph, 'condition', 'otherwise')).toBe(false);
  });

  it('descendantsOf follows edges', () => {
    expect([...descendantsOf(base(), 'trigger')]).toEqual(['condition', 'email']);
  });

  it('moveNode changes only the position', () => {
    const graph = moveNode(base(), 'email', { x: 5, y: 6 });
    expect(graph.nodes.find((node) => node.id === 'email')!.position).toEqual({ x: 5, y: 6 });
  });

  it('updateNodeConfig replaces the config', () => {
    const graph = updateNodeConfig(base(), 'email', { subject: 'Hi' });
    expect(graph.nodes.find((node) => node.id === 'email')!.data.config).toEqual({ subject: 'Hi' });
  });

  it('setNodeStep swaps the block and resets the config to its defaults', () => {
    const graph = setNodeStep(base(), 'email', 'add_order_note');
    const data = graph.nodes.find((node) => node.id === 'email')!.data;
    expect(data.block).toBe('add_order_note');
    expect(data.config).not.toHaveProperty('subject');
  });
});
