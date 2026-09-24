import type { Edge, Node } from '@xyflow/react';
import type { WorkflowGraph, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

/** ReactFlow node typed by our data + kind. `type` picks the component from `nodeTypes`. */
export type FlowNode = Node<WorkflowNodeData, WorkflowNodeKind>;

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

export function toFlowEdges(graph: WorkflowGraph): Edge[] {
  return graph.edges.map((edge) => ({
    id: edge.id,
    source: edge.source,
    target: edge.target,
    sourceHandle: edge.sourceHandle,
    label: edge.sourceHandle ? branchLabels[edge.sourceHandle] : undefined,
  }));
}
