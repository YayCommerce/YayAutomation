import '@xyflow/react/dist/style.css';

import { useMemo, useState } from 'react';
import { useStore } from '@tanstack/react-form';
import { Background, Controls, ReactFlow, type NodeChange } from '@xyflow/react';

import { useTheme } from '@/components/ui/theme-provider';
import { useWorkflowFormContext } from './useWorkflowForm';
import { canvasStyle, toFlowEdges, toFlowNodes, type FlowNode } from './workflow.flow';
import { moveNode, removeNode } from './workflow.graph';
import { nodeTypes } from './WorkflowNodeCard';

/** Canvas-only node state. Kept out of the form so selecting a node doesn't make the workflow dirty. */
type NodeViewState = Pick<FlowNode, 'measured' | 'selected' | 'dragging'>;

export function WorkflowCanvas({ onNodeClick }: { onNodeClick: (nodeId: string) => void }) {
  const { theme } = useTheme();
  const form = useWorkflowFormContext();
  const graph = useStore(form.store, (state) => state.values.graph);
  const [viewState, setViewState] = useState<Record<string, NodeViewState>>({});

  // Controlled ReactFlow needs `measured` on every node it gets back, hence the merge.
  const nodes = useMemo(
    () => toFlowNodes(graph).map((node) => ({ ...node, ...viewState[node.id] })),
    [graph, viewState],
  );
  const edges = useMemo(() => toFlowEdges(graph), [graph]);

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

    // Workflow changes (move, delete) → form. Skipped otherwise, so selecting doesn't make the form dirty.
    if (changes.some((change) => (change.type === 'position' && change.position) || change.type === 'remove')) {
      form.setFieldValue('graph', (graph) =>
        changes.reduce((next, change) => {
          if (change.type === 'position' && change.position) return moveNode(next, change.id, change.position);
          if (change.type === 'remove') return removeNode(next, change.id);
          return next;
        }, graph),
      );
    }
  };

  return (
    <ReactFlow
      nodes={nodes}
      edges={edges}
      nodeTypes={nodeTypes}
      onNodesChange={onNodesChange}
      onNodeClick={(_, node) => onNodeClick(node.id)}
      colorMode={theme}
      style={canvasStyle}
      nodesConnectable={false}
      fitView
    >
      <Background />
      <Controls showInteractive={false} />
    </ReactFlow>
  );
}
