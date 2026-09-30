import '@xyflow/react/dist/style.css';

import { useMemo, type ReactNode } from 'react';
import { Background, Controls, ReactFlow, type PanelPosition } from '@xyflow/react';
import type { WorkflowGraph } from '@yay-automation/types';

import { useTheme } from '@/components/ui/theme-provider';
import { canvasStyle, toFlowEdges, toFlowNodes } from './workflow.flow';
import { readOnlyNodeTypes } from './WorkflowNodeCard';

/**
 * Same look as the editor canvas, but view-only: pan and zoom only. Uncontrolled (`default*` props)
 * since nothing is written back. Mount with a `key` if `graph` can change.
 */
export function ReadOnlyCanvas({
  graph,
  controlsPosition,
  controls,
}: {
  graph: WorkflowGraph;
  /** Moves the zoom controls out of the way of overlays (e.g. the preview's About panel). */
  controlsPosition?: PanelPosition;
  /** Extra `ControlButton`s after zoom and fit (e.g. the Overview's edit button). */
  controls?: ReactNode;
}) {
  const { theme } = useTheme();
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
    >
      <Background />
      <Controls showInteractive={false} position={controlsPosition}>
        {controls}
      </Controls>
    </ReactFlow>
  );
}
