import { createContext, type CSSProperties } from 'react';
import type { Edge, Node } from '@xyflow/react';
import type { WorkflowGraph, WorkflowNodeData, WorkflowNodeKind } from '@yaymatik/types';

import { triggerOf } from '../../model/graph';
import { stepOf } from '../../steps';

/** The canvas workflow's `triggerOf()`, for its cards' summaries. */
export const CanvasTriggerContext = createContext<string | null>(null);

/** ReactFlow node typed by our data + kind. `type` picks the component from `nodeTypes`. */
export type FlowNode = Node<WorkflowNodeData, WorkflowNodeKind>;

// ReactFlow ships unlayered CSS, so Tailwind utilities can't override it; its CSS variables and
// inline styles can.
export const lineColor = 'color-mix(in oklab, var(--muted-foreground) 55%, transparent)';

/** Id of the arrowhead marker every edge ends with, defined by `EdgeArrowDefs`. */
export const EDGE_ARROW_ID = 'yaymatik-edge-arrow';

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

/**
 * Least room between a card's bottom and the top of a step below it: the edge and the "+" button.
 * What `ROW_GAP` leaves under an 86px card.
 */
const MIN_EDGE_GAP = 84;

/**
 * Where to draw each step: pushed down when it sits closer than `MIN_EDGE_GAP` below its parent's
 * bottom (a card grew, e.g. a condition with several criteria). Parents are placed first, so a push
 * reaches the steps below. Never moves a step up; stored positions stay as they are.
 *
 * @param heights Measured card heights by node id; a card not measured yet pushes nothing.
 */
export function spacedPositions(graph: WorkflowGraph, heights: Record<string, number | undefined>) {
  const parent = new Map(graph.edges.map((edge) => [edge.target, edge.source]));
  const children = new Map<string, string[]>();
  for (const { source, target } of graph.edges) children.set(source, [...(children.get(source) ?? []), target]);
  const stored = new Map(graph.nodes.map((node) => [node.id, node.position]));

  const placed = new Map<string, { x: number; y: number }>();
  const queue = graph.nodes.filter((node) => !parent.has(node.id)).map((node) => node.id);
  for (const id of queue) placed.set(id, stored.get(id)!);
  while (queue.length) {
    const id = queue.shift()!;
    const { y } = placed.get(id)!;
    const height = heights[id];
    for (const child of children.get(id) ?? []) {
      const position = stored.get(child);
      if (!position || placed.has(child)) continue;
      const min = height === undefined ? -Infinity : y + height + MIN_EDGE_GAP;
      placed.set(child, { x: position.x, y: Math.max(position.y, min) });
      queue.push(child);
    }
  }
  return placed;
}

/** `heights`: measured card heights, to space steps out (see `spacedPositions`). */
export function toFlowNodes(graph: WorkflowGraph, heights: Record<string, number | undefined> = {}): FlowNode[] {
  const positions = spacedPositions(graph, heights);
  return graph.nodes.map((node) => ({
    id: node.id,
    type: node.kind,
    position: positions.get(node.id) ?? node.position,
    data: node.data,
    // Blocks keyboard delete (Backspace) for the trigger.
    deletable: node.kind !== 'trigger',
  }));
}

/** Not set up yet: no step chosen, or settings still missing (e.g. a condition with no criteria). */
function isPending(data: WorkflowNodeData, trigger: string | null) {
  const stepDef = stepOf(data);
  return !stepDef || stepDef.firstError(data.config, trigger) !== null;
}

/**
 * The last stretch always runs straight down into the card, so the arrow lines up with it. In the
 * editor lines are dashed, and a line into a step that isn't set up yet runs until it is.
 */
export function toFlowEdges(graph: WorkflowGraph, { editing = false } = {}): Edge[] {
  const trigger = triggerOf(graph);
  const pending = new Set(graph.nodes.filter((node) => isPending(node.data, trigger)).map((node) => node.id));
  return graph.edges.map((edge) => ({
    id: edge.id,
    source: edge.source,
    target: edge.target,
    sourceHandle: edge.sourceHandle,
    type: 'curved',
    animated: editing && pending.has(edge.target),
    style: editing ? { strokeDasharray: '5 5', strokeWidth: 1.5 } : { strokeWidth: 1.5 },
    markerEnd: EDGE_ARROW_ID,
  }));
}
