import '@xyflow/react/dist/style.css';

import { useMemo, type CSSProperties } from 'react';
import { Background, Controls, ReactFlow } from '@xyflow/react';
import type { WorkflowGraph } from '@yay-automation/types';

import { useTheme } from '@/components/ui/theme-provider';
import { toFlowEdges, toFlowNodes } from './workflow.flow';
import { nodeTypes } from './WorkflowNodeCard';

// ReactFlow ships unlayered CSS, so Tailwind utilities can't override it; its CSS variables can.
const canvasStyle = { '--xy-background-color': 'var(--muted-400)' } as CSSProperties;

export function WorkflowCanvas({ graph }: { graph: WorkflowGraph }) {
  const { theme } = useTheme();

  const nodes = useMemo(() => toFlowNodes(graph), [graph]);
  const edges = useMemo(() => toFlowEdges(graph), [graph]);

  return (
    <ReactFlow
      nodes={nodes}
      edges={edges}
      nodeTypes={nodeTypes}
      colorMode={theme}
      style={canvasStyle}
      nodesDraggable={false}
      nodesConnectable={false}
      fitView
    >
      <Background />
      <Controls showInteractive={false} />
    </ReactFlow>
  );
}
