import type { CSSProperties } from 'react';
import { MarkerType, type Edge, type Node } from '@xyflow/react';
import type { WorkflowGraph, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

import { blockOf } from '../../blocks';

/** ReactFlow node typed by our data + kind. `type` picks the component from `nodeTypes`. */
export type FlowNode = Node<WorkflowNodeData, WorkflowNodeKind>;

// ReactFlow ships unlayered CSS, so Tailwind utilities can't override it; its CSS variables and
// inline styles can.
const lineColor = 'color-mix(in oklab, var(--muted-foreground) 55%, transparent)';

export const canvasStyle = {
  '--xy-background-color': 'var(--muted)',
  '--xy-edge-stroke-default': lineColor,
} as CSSProperties;

/** Where an edge leaves a card: a small grey tab hanging under its bottom edge. */
export const sourceHandleStyle: CSSProperties = {
  width: 24,
  height: 7,
  minWidth: 0,
  minHeight: 0,
  border: 0,
  borderRadius: '0 0 6px 6px',
  background: lineColor,
  transform: 'translate(-50%, 100%)',
};

/** Where an edge arrives: invisible, the arrow points at the card itself. */
export const targetHandleStyle: CSSProperties = {
  width: 1,
  height: 1,
  minWidth: 0,
  minHeight: 0,
  border: 0,
  opacity: 0,
  transform: 'translate(-50%, 0)',
};

const branchLabels = {
  then: __('Then', 'yay-automation'),
  otherwise: __('Otherwise', 'yay-automation'),
};

export function toFlowNodes(graph: WorkflowGraph): FlowNode[] {
  return graph.nodes.map((node) => ({
    id: node.id,
    type: node.kind,
    position: node.position,
    data: node.data,
    // Blocks keyboard delete (Backspace) for the trigger.
    deletable: node.kind !== 'trigger',
  }));
}

/** Not set up yet: no block chosen, or settings still missing (e.g. a condition with no criteria). */
function isPending(data: WorkflowNodeData) {
  const block = blockOf(data);
  return !block || block.firstError(data.config) !== null;
}

/**
 * Dashed lines with an open arrow, like Shopify Flow. A line into a step that isn't set up yet
 * runs (ReactFlow's `animated` dashes) until it is.
 */
export function toFlowEdges(graph: WorkflowGraph): Edge[] {
  const pending = new Set(graph.nodes.filter((node) => isPending(node.data)).map((node) => node.id));
  return graph.edges.map((edge) => ({
    id: edge.id,
    source: edge.source,
    target: edge.target,
    sourceHandle: edge.sourceHandle,
    label: edge.sourceHandle ? branchLabels[edge.sourceHandle] : undefined,
    animated: pending.has(edge.target),
    style: { strokeDasharray: '5 5', strokeWidth: 1.5 },
    markerEnd: { type: MarkerType.Arrow, color: lineColor, width: 16, height: 16 },
  }));
}
