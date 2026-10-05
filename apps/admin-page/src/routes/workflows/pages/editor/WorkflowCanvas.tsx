import '@xyflow/react/dist/style.css';

import { useMemo, useState } from 'react';
import { useStore } from '@tanstack/react-form';
import { Background, Controls, ReactFlow, type FitViewOptions, type NodeChange } from '@xyflow/react';

import { useTheme } from '@/components/ui/theme-provider';
import { moveNode } from '../../model/graph';
import { canvasStyle, toFlowEdges, toFlowNodes, type FlowNode } from '../../shared/canvas/flow';
import { useEditorContext } from './editor.context';
import { PANEL_WIDTH } from './StepPanel';
import { useWorkflowFormContext } from './useWorkflowForm';
import { nodeTypes } from './WorkflowNodeCard';

/** Canvas-only node state. Kept out of the form so selecting a node doesn't make the workflow dirty. */
type NodeViewState = Pick<FlowNode, 'measured' | 'selected' | 'dragging'>;

/** Clear of the step panel: for the first fit and the zoom controls. */
const besidePanel = PANEL_WIDTH + 24;

/** `onPaneClick`: a click on empty canvas, which closes the step panel like in Shopify Flow. */
export function WorkflowCanvas({ panelOpen, onPaneClick }: { panelOpen: boolean; onPaneClick: () => void }) {
  const { theme } = useTheme();
  const form = useWorkflowFormContext();
  const { openPanel, requestDelete } = useEditorContext();
  // Decided once: fitting only happens on mount.
  // Never zoomed in past 100%: a small graph would otherwise blow up to fill the screen.
  const [fitViewOptions] = useState<FitViewOptions>(() => ({
    maxZoom: 1,
    padding: panelOpen ? { left: `${besidePanel}px`, x: 0.2, y: 0.2 } : 0.2,
  }));
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
      onNodesChange={onNodesChange}
      onNodeClick={(_, node) => openPanel(node.id)}
      onPaneClick={onPaneClick}
      // Backspace: same delete as the card's button (edges out of the form too), not ReactFlow's own.
      onBeforeDelete={async ({ nodes }) => {
        if (nodes.length) requestDelete(nodes.map((node) => node.id));
        return false;
      }}
      colorMode={theme}
      style={canvasStyle}
      nodesConnectable={false}
      fitView
      fitViewOptions={fitViewOptions}
    >
      <Background />
      <Controls showInteractive={false} style={panelOpen ? { left: besidePanel } : undefined} />
    </ReactFlow>
  );
}
