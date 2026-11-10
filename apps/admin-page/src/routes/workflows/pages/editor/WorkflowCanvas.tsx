import '@xyflow/react/dist/style.css';

import { useEffect, useMemo, useRef, useState } from 'react';
import { useStore } from '@tanstack/react-form';
import {
  Background,
  Controls,
  ReactFlow,
  useStore as useFlowStore,
  useReactFlow,
  type FitViewOptions,
  type NodeChange,
} from '@xyflow/react';

import { moveNode } from '../../model/graph';
import { edgeTypes } from '../../shared/canvas/CurvedEdge';
import { EdgeArrowDefs } from '../../shared/canvas/EdgeArrow';
import { canvasStyle, toFlowEdges, toFlowNodes, type FlowNode } from '../../shared/canvas/flow';
import { useEditorContext } from './editor.context';
import { useWorkflowFormContext } from './useWorkflowForm';
import { nodeTypes } from './WorkflowNodeCard';

/** Canvas-only node state. Kept out of the form so selecting a node doesn't make the workflow dirty. */
type NodeViewState = Pick<FlowNode, 'measured' | 'selected' | 'dragging'>;

/**
 * Fits the graph into the canvas area the step panel leaves free (`inset` px on the left). Never
 * zoomed in past 100%: a small graph would otherwise blow up to fill the screen.
 */
const fitOptions = (inset: number): FitViewOptions => ({
  maxZoom: 1,
  padding: inset ? { left: `${inset}px`, x: 0.2, y: 0.2 } : 0.2,
});

/**
 * `panelInset`: px the open step panel covers on the left (0 when closed).
 * `onPaneClick`: a click on empty canvas, which closes the step panel.
 */
export function WorkflowCanvas({ panelInset, onPaneClick }: { panelInset: number; onPaneClick: () => void }) {
  const form = useWorkflowFormContext();
  const { openNodeId, openPanel, requestDelete } = useEditorContext();
  // The first fit; later ones come from `ViewKeeper`.
  const [fitViewOptions] = useState(() => fitOptions(panelInset));
  const graph = useStore(form.store, (state) => state.values.graph);
  const [viewState, setViewState] = useState<Record<string, NodeViewState>>({});

  // Controlled ReactFlow needs `measured` on every node it gets back, hence the merge.
  const nodes = useMemo(() => {
    const heights = Object.fromEntries(Object.entries(viewState).map(([id, state]) => [id, state.measured?.height]));
    return toFlowNodes(graph, heights).map((node) => ({ ...node, ...viewState[node.id] }));
  }, [graph, viewState]);
  const edges = useMemo(() => toFlowEdges(graph, { editing: true }), [graph]);

  const onNodesChange = (changes: NodeChange<FlowNode>[]) => {
    // Canvas-only changes → local state. Functional update so back-to-back batches don't overwrite each other.
    setViewState((prev) => {
      const next = { ...prev };
      for (const change of changes) {
        if (change.type === 'dimensions' && change.dimensions) {
          next[change.id] = { ...next[change.id], measured: change.dimensions };
        } else if (change.type === 'select') {
          next[change.id] = { ...next[change.id], selected: change.selected };
        } else if (change.type === 'position') {
          next[change.id] = { ...next[change.id], dragging: change.dragging };
        }
      }
      return next;
    });

    // Moves → form. Skipped otherwise, so selecting doesn't make the form dirty. Deletes go through
    // `onBeforeDelete` instead, so they follow the same rules as the card's delete button.
    if (changes.some((change) => change.type === 'position' && change.position)) {
      form.setFieldValue('graph', (graph) =>
        changes.reduce(
          (next, change) =>
            change.type === 'position' && change.position ? moveNode(next, change.id, change.position) : next,
          graph,
        ),
      );
    }
  };

  return (
    <ReactFlow
      nodes={nodes}
      edges={edges}
      nodeTypes={nodeTypes}
      edgeTypes={edgeTypes}
      onNodesChange={onNodesChange}
      onNodeClick={(_, node) => openPanel(node.id)}
      onPaneClick={onPaneClick}
      // Backspace: same delete as the card's button (edges out of the form too), not ReactFlow's own.
      onBeforeDelete={async ({ nodes }) => {
        if (nodes.length) requestDelete(nodes.map((node) => node.id));
        return false;
      }}
      style={canvasStyle}
      nodesConnectable={false}
      // Wheel and trackpad move around a long workflow; Ctrl/Cmd + wheel and pinch zoom.
      panOnScroll
      zoomOnScroll={false}
      zoomOnPinch
      zoomActivationKeyCode={['Meta', 'Control']}
      fitView
      fitViewOptions={fitViewOptions}
    >
      <Background />
      <EdgeArrowDefs />
      <Controls showInteractive={false} style={panelInset ? { left: panelInset } : undefined} />
      <ViewKeeper panelInset={panelInset} openNodeId={openNodeId} />
    </ReactFlow>
  );
}

/** Frames to wait for a new step's size before giving up on bringing it into view. */
const MEASURE_FRAMES = 30;

/**
 * Keeps the graph usable at any screen size: re-fits it when the canvas is resized (window, admin
 * menu, device), and brings the step whose panel opens into the area beside the panel when the panel
 * would hide it or it's off screen.
 */
function ViewKeeper({ panelInset, openNodeId }: { panelInset: number; openNodeId: string | null }) {
  const { fitView, getInternalNode, getViewport, setViewport } = useReactFlow();
  const width = useFlowStore((state) => state.width);
  const height = useFlowStore((state) => state.height);

  // The first size is ReactFlow's own mount fit.
  const lastSize = useRef<string | null>(null);
  useEffect(() => {
    if (!width || !height) return;
    const size = `${width}x${height}`;
    if (lastSize.current && lastSize.current !== size) void fitView({ ...fitOptions(panelInset), duration: 200 });
    lastSize.current = size;
  }, [width, height, panelInset, fitView]);

  // The panel open at mount is already in the first fit. Moving now would read the viewport before
  // that fit applies and race it. Compared, not a "mounted" flag: StrictMode runs this effect twice.
  const lastOpen = useRef(openNodeId);
  useEffect(() => {
    if (openNodeId === lastOpen.current) return;
    lastOpen.current = openNodeId;
    if (!openNodeId) return;
    let frame = 0;
    const bringIntoView = (framesLeft: number) => {
      const node = getInternalNode(openNodeId);
      const w = node?.measured.width;
      const h = node?.measured.height;
      // A step just added is measured by a ResizeObserver, which runs after this frame's callbacks.
      if (!node || !w || !h) {
        if (framesLeft > 0) frame = requestAnimationFrame(() => bringIntoView(framesLeft - 1));
        return;
      }
      const { x, y, zoom } = getViewport();
      const { x: nodeX, y: nodeY } = node.internals.positionAbsolute;
      const left = nodeX * zoom + x;
      const top = nodeY * zoom + y;
      const inView = left >= panelInset && left + w * zoom <= width && top >= 0 && top + h * zoom <= height;
      if (inView) return;
      // Center it in the free area, same zoom.
      const centerX = panelInset + (width - panelInset) / 2;
      void setViewport(
        { x: centerX - (nodeX + w / 2) * zoom, y: height / 2 - (nodeY + h / 2) * zoom, zoom },
        { duration: 300 },
      );
    };
    frame = requestAnimationFrame(() => bringIntoView(MEASURE_FRAMES));
    return () => cancelAnimationFrame(frame);
    // Only when another step's panel opens, not on every resize or pan.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openNodeId]);

  return null;
}
