import type { CSSProperties } from 'react';
import type { Edge, Node } from '@xyflow/react';
import type { WorkflowGraph, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

import { blockOf } from '../../blocks';

/** ReactFlow node typed by our data + kind. `type` picks the component from `nodeTypes`. */
export type FlowNode = Node<WorkflowNodeData, WorkflowNodeKind>;

// ReactFlow ships unlayered CSS, so Tailwind utilities can't override it; its CSS variables and
// inline styles can.
export const lineColor = 'color-mix(in oklab, var(--muted-foreground) 55%, transparent)';

/** Id of the arrowhead marker every edge ends with, defined by `EdgeArrowDefs`. */
export const EDGE_ARROW_ID = 'yay-automation-edge-arrow';

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
 * Curved lines (`CurvedEdge`) ending in an open arrow (`EdgeArrowDefs`), like Shopify Flow: the last
 * stretch always runs straight down into the card, so the arrow lines up with it. In the editor they're dashed,
 * and a line into a step that isn't set up yet runs (ReactFlow's `animated` dashes) until it is;
 * read-only canvases (overview, template preview) draw them solid.
 */
export function toFlowEdges(graph: WorkflowGraph, { editing = false } = {}): Edge[] {
  const pending = new Set(graph.nodes.filter((node) => isPending(node.data)).map((node) => node.id));
  return graph.edges.map((edge) => ({
    id: edge.id,
    source: edge.source,
    target: edge.target,
    sourceHandle: edge.sourceHandle,
    label: edge.sourceHandle ? branchLabels[edge.sourceHandle] : undefined,
    type: 'curved',
    animated: editing && pending.has(edge.target),
    style: editing ? { strokeDasharray: '5 5', strokeWidth: 1.5 } : { strokeWidth: 1.5 },
    markerEnd: EDGE_ARROW_ID,
  }));
}
