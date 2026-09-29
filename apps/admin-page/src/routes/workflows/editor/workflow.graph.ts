import type { WorkflowBranch, WorkflowGraph, WorkflowNodeKind } from '@yay-automation/types';

import { newNodeData } from '../blocks';

/**
 * Pure graph updates: (graph, …args) → new graph. The form calls these through
 * `setFieldValue('graph', (graph) => …)`, so each edit is one atomic value change.
 */

/** Layout grid for new nodes. Template graphs use the same grid so added steps line up. */
export const ROW_GAP = 150;
export const BRANCH_OFFSET = 150;

/** Short unique id with a readable prefix. Not `crypto.randomUUID()`: it's undefined on non-HTTPS WP sites. */
export function createId(prefix: string) {
  return `${prefix}-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}

/** Graph for a workflow started from scratch: a single trigger to configure. */
export function blankGraph(): WorkflowGraph {
  return {
    nodes: [{ id: 'trigger', kind: 'trigger', position: { x: 0, y: 0 }, data: newNodeData('trigger') }],
    edges: [],
  };
}

/** True when the node's source handle (or condition branch) already has an outgoing edge. */
export function hasChild(graph: WorkflowGraph, nodeId: string, branch?: WorkflowBranch) {
  return graph.edges.some((edge) => edge.source === nodeId && edge.sourceHandle === branch);
}

/** Appends a node below its parent and connects it. */
export function addNode(
  graph: WorkflowGraph,
  parentId: string,
  kind: Exclude<WorkflowNodeKind, 'trigger'>,
  branch?: WorkflowBranch,
): WorkflowGraph {
  const parent = graph.nodes.find((node) => node.id === parentId);
  if (!parent) return graph;

  const id = createId(kind);
  const offsetX = branch === 'then' ? -BRANCH_OFFSET : branch === 'otherwise' ? BRANCH_OFFSET : 0;

  return {
    nodes: [
      ...graph.nodes,
      {
        id,
        kind,
        position: { x: parent.position.x + offsetX, y: parent.position.y + ROW_GAP },
        data: newNodeData(kind),
      },
    ],
    edges: [...graph.edges, { id: `e-${parentId}-${id}`, source: parentId, target: id, sourceHandle: branch }],
  };
}

/**
 * How deleting a node plays out: `none` for the trigger (it can't be removed), `branches` for a
 * condition with steps under it (they go too, so ask first), `simple` otherwise.
 */
export function deletionKind(graph: WorkflowGraph, nodeId: string): 'none' | 'simple' | 'branches' {
  const node = graph.nodes.find((n) => n.id === nodeId);
  if (!node || node.kind === 'trigger') return 'none';
  return node.kind === 'condition' && graph.edges.some((edge) => edge.source === nodeId) ? 'branches' : 'simple';
}

/**
 * Removes a node. A condition takes every step below it along. Any other node hands its single
 * child to its parent, on the same branch, so the flow stays connected. The trigger can't be removed.
 */
export function removeNode(graph: WorkflowGraph, nodeId: string): WorkflowGraph {
  const node = graph.nodes.find((n) => n.id === nodeId);
  if (!node || node.kind === 'trigger') return graph;

  if (node.kind === 'condition') {
    const removed = descendantsOf(graph, nodeId).add(nodeId);
    return {
      nodes: graph.nodes.filter((n) => !removed.has(n.id)),
      edges: graph.edges.filter((edge) => !removed.has(edge.source) && !removed.has(edge.target)),
    };
  }

  const incoming = graph.edges.find((edge) => edge.target === nodeId);
  const outgoing = graph.edges.filter((edge) => edge.source === nodeId);
  const edges = graph.edges.filter((edge) => edge.source !== nodeId && edge.target !== nodeId);
  if (incoming && outgoing.length === 1) {
    const child = outgoing[0].target;
    edges.push({
      id: `e-${incoming.source}-${child}`,
      source: incoming.source,
      target: child,
      sourceHandle: incoming.sourceHandle,
    });
  }
  return { nodes: graph.nodes.filter((n) => n.id !== nodeId), edges };
}

/** Ids of every node below `nodeId`, following edges. */
export function descendantsOf(graph: WorkflowGraph, nodeId: string) {
  const found = new Set<string>();
  const queue = [nodeId];
  while (queue.length) {
    const id = queue.shift()!;
    for (const edge of graph.edges) {
      if (edge.source === id && !found.has(edge.target)) {
        found.add(edge.target);
        queue.push(edge.target);
      }
    }
  }
  return found;
}

export function moveNode(graph: WorkflowGraph, nodeId: string, position: { x: number; y: number }): WorkflowGraph {
  return {
    ...graph,
    nodes: graph.nodes.map((node) => (node.id === nodeId ? { ...node, position } : node)),
  };
}

export function updateNodeConfig(graph: WorkflowGraph, nodeId: string, config: Record<string, unknown>): WorkflowGraph {
  return {
    ...graph,
    nodes: graph.nodes.map((node) => (node.id === nodeId ? { ...node, data: { ...node.data, config } } : node)),
  };
}
