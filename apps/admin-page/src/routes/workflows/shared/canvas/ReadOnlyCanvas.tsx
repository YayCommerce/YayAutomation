import '@xyflow/react/dist/style.css';

import { useEffect, useMemo, type ReactNode } from 'react';
import { Background, Panel, ReactFlow, useReactFlow, useStore, type PanelPosition } from '@xyflow/react';
import type { WorkflowGraph } from '@yay-automation/types';
import { Maximize2, Minus, Plus } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { useTheme } from '@/components/ui/theme-provider';
import { canvasStyle, toFlowEdges, toFlowNodes } from './flow';
import { readOnlyNodeTypes } from './NodeCardView';

// Zoom out as far as needed so the whole graph fits on narrow screens. By default never zoom small
// graphs in past 100%.
const MIN_ZOOM = 0.2;

type FitViewOptions = { minZoom: number; maxZoom: number; padding: number; duration: number };

/**
 * Same look as the editor canvas, but view-only: pan and zoom only. Uncontrolled (`default*` props)
 * since nothing is written back. Mount with a `key` if `graph` can change.
 */
export function ReadOnlyCanvas({
  graph,
  controlsPosition = 'bottom-left',
  controls,
  maxZoom = 1,
}: {
  graph: WorkflowGraph;
  /** Moves the zoom controls out of the way of overlays (e.g. the preview's About panel). */
  controlsPosition?: PanelPosition;
  /** Extra buttons before zoom and fit (e.g. the Overview's edit button). */
  controls?: ReactNode;
  /** Largest zoom fit may reach. Full-page canvases pass 2 (the editor's default) to fill the space. */
  maxZoom?: number;
}) {
  const { theme } = useTheme();
  const fitViewOptions = useMemo(() => ({ minZoom: MIN_ZOOM, maxZoom, padding: 0.2, duration: 0 }), [maxZoom]);
  const nodes = useMemo(() => toFlowNodes(graph), [graph]);
  const edges = useMemo(() => toFlowEdges(graph), [graph]);

  return (
    <ReactFlow
      defaultNodes={nodes}
      defaultEdges={edges}
      nodeTypes={readOnlyNodeTypes}
      colorMode={theme}
      style={canvasStyle}
      nodesDraggable={false}
      nodesConnectable={false}
      elementsSelectable={false}
      deleteKeyCode={null}
      fitView
      fitViewOptions={fitViewOptions}
      minZoom={MIN_ZOOM}
      proOptions={{ hideAttribution: true }}
    >
      <FitOnResize fitViewOptions={fitViewOptions} />
      <Background />
      <Panel position={controlsPosition} className="flex items-center gap-2">
        {controls}
        <CanvasControls fitViewOptions={fitViewOptions} />
      </Panel>
    </ReactFlow>
  );
}

/**
 * `fitView` alone only fits on mount; a canvas that is resized (window, sidebar, a tab shown after
 * mounting at 0px) would leave the graph off-screen. Refits whenever React Flow's measured size
 * changes, debounced so dragging a window edge refits once.
 */
function FitOnResize({ fitViewOptions }: { fitViewOptions: FitViewOptions }) {
  const { fitView } = useReactFlow();
  const width = useStore((s) => s.width);
  const height = useStore((s) => s.height);

  useEffect(() => {
    if (!width || !height) return;
    const timer = window.setTimeout(() => void fitView(fitViewOptions), 100);
    return () => window.clearTimeout(timer);
  }, [fitView, fitViewOptions, width, height]);

  return null;
}

/** Zoom out / in as one group, then fit. */
function CanvasControls({ fitViewOptions }: { fitViewOptions: FitViewOptions }) {
  const { zoomIn, zoomOut, fitView } = useReactFlow();

  return (
    <>
      <ButtonGroup>
        <Button
          variant="outline"
          size="icon-sm"
          className="bg-card"
          onClick={() => zoomOut()}
          aria-label={__('Zoom out', 'yay-automation')}
        >
          <Minus />
        </Button>
        <Button
          variant="outline"
          size="icon-sm"
          className="bg-card"
          onClick={() => zoomIn()}
          aria-label={__('Zoom in', 'yay-automation')}
        >
          <Plus />
        </Button>
      </ButtonGroup>
      <Button
        variant="outline"
        size="icon-sm"
        className="bg-card"
        onClick={() => fitView(fitViewOptions)}
        aria-label={__('Fit view', 'yay-automation')}
      >
        <Maximize2 />
      </Button>
    </>
  );
}
