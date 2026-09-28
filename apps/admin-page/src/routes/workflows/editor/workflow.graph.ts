import type { WorkflowBranch, WorkflowGraph, WorkflowNodeKind } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

/**
 * Pure graph updates: (graph, …args) → new graph. The form calls these through
 * `setFieldValue('graph', (graph) => …)`, so each edit is one atomic value change.
 */

/** Layout grid for new nodes. Template graphs use the same grid so added steps line up. */
export const ROW_GAP = 150;
export const BRANCH_OFFSET = 150;

const newTitles = {
  condition: __('New condition', 'yay-automation'),
  action: __('New action', 'yay-automation'),
};

/** Short unique id with a readable prefix. Not `crypto.randomUUID()`: it's undefined on non-HTTPS WP sites. */
export function createId(prefix: string) {
  return `${prefix}-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}

/** Graph for a workflow started from scratch: a single trigger to configure. */
export function blankGraph(): WorkflowGraph {
  return {
    nodes: [
      {
        id: 'trigger',
        kind: 'trigger',
        position: { x: 0, y: 0 },
        data: { title: __('Select a trigger', 'yay-automation') },
      },
    ],
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
        data: { title: newTitles[kind] },
      },
    ],
    edges: [...graph.edges, { id: `e-${parentId}-${id}`, source: parentId, target: id, sourceHandle: branch }],
  };
}

/** Removes a node and its edges. Children are left unconnected. The trigger can't be removed. */
export function removeNode(graph: WorkflowGraph, nodeId: string): WorkflowGraph {
  const node = graph.nodes.find((n) => n.id === nodeId);
  if (!node || node.kind === 'trigger') return graph;

  return {
    nodes: graph.nodes.filter((n) => n.id !== nodeId),
    edges: graph.edges.filter((edge) => edge.source !== nodeId && edge.target !== nodeId),
  };
}

export function moveNode(graph: WorkflowGraph, nodeId: string, position: { x: number; y: number }): WorkflowGraph {
  return {
    ...graph,
    nodes: graph.nodes.map((node) => (node.id === nodeId ? { ...node, position } : node)),
  };
}
