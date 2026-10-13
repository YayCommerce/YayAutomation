import type { StepId, WorkflowBranch, WorkflowGraph, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';

import { newNodeData, withStep } from '../steps';

/**
 * Pure graph updates: (graph, …args) → new graph. The form calls these through
 * `setFieldValue('graph', (graph) => …)`, so each edit is one atomic value change.
 */

/** Layout grid for new nodes. Template graphs use the same grid so added steps line up. */
// Top-to-top: a card is 86px tall, leaving room for a clear edge and the "+" below it.
// Branches sit far enough apart that two 290px cards side by side keep a gap.
export const ROW_GAP = 170;
export const BRANCH_OFFSET = 200;
/** Every card's width, so a step and its branches line up. Positions are cards' top-left corners. */
export const CARD_WIDTH = 290;

/** Short unique id with a readable prefix. Not `crypto.randomUUID()`: it's undefined on non-HTTPS WP sites. */
export function createId(prefix: string) {
  return `${prefix}-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
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
  /** Pass one to know the new step's id (e.g. to open its panel). */
  id = createId(kind),
): WorkflowGraph {
  const parent = graph.nodes.find((node) => node.id === parentId);
  if (!parent) return graph;

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
 * Removes just this node and its edges. Steps below it stay on the canvas, unlinked from the
 * trigger, until the user deletes them or rebuilds the flow. The trigger can't be removed.
 */
export function removeNode(graph: WorkflowGraph, nodeId: string): WorkflowGraph {
  const node = graph.nodes.find((n) => n.id === nodeId);
  if (!node || node.kind === 'trigger') return graph;

  return {
    nodes: graph.nodes.filter((n) => n.id !== nodeId),
    edges: graph.edges.filter((edge) => edge.source !== nodeId && edge.target !== nodeId),
  };
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

/** Applies `change` to one node's data. */
function updateNodeData(
  graph: WorkflowGraph,
  nodeId: string,
  change: (data: WorkflowNodeData) => WorkflowNodeData,
): WorkflowGraph {
  return {
    ...graph,
    nodes: graph.nodes.map((node) => (node.id === nodeId ? { ...node, data: change(node.data) } : node)),
  };
}

export const updateNodeConfig = (graph: WorkflowGraph, nodeId: string, config: Record<string, unknown>) =>
  updateNodeData(graph, nodeId, (data) => ({ ...data, config }));

/** Chooses (or swaps) a trigger's or action's step; its config resets to that step's defaults. */
export const setNodeStep = (graph: WorkflowGraph, nodeId: string, stepId: StepId) =>
  updateNodeData(graph, nodeId, (data) => withStep(data, stepId));
