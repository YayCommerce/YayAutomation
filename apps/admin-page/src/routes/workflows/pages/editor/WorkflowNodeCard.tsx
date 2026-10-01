import type { MouseEvent } from 'react';
import { useStore } from '@tanstack/react-form';
import type { NodeProps } from '@xyflow/react';
import type { WorkflowBranch, WorkflowNodeKind } from '@yay-automation/types';
import { GitBranch, Play, Plus, Trash2 } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { addNode, hasChild } from '../../model/graph';
import type { FlowNode } from '../../shared/canvas/flow';
import { branchLeft, kindMeta, NodeCardView } from '../../shared/canvas/NodeCardView';
import { useEditorContext } from './editor.context';
import { useWorkflowFormContext } from './useWorkflowForm';

/**
 * Editable card: adds delete and "+" controls on top of the view. `nodeTypes` maps each kind here.
 * Clicking the card opens its settings (the canvas's `onNodeClick`), so the controls stop propagation.
 */
export function WorkflowNodeCard({ id, type, data, selected }: NodeProps<FlowNode>) {
  const { openNodeId, issueNodeIds, issueMode, requestDelete } = useEditorContext();

  const remove = (event: MouseEvent) => {
    event.stopPropagation();
    requestDelete([id]);
  };

  return (
    <NodeCardView
      type={type}
      data={data}
      selected={selected || openNodeId === id}
      issue={issueNodeIds.has(id) ? issueMode : undefined}
      className="cursor-pointer"
      actions={
        type !== 'trigger' && (
          <Button
            variant="ghost"
            size="icon-sm"
            // `nodrag` stops ReactFlow from treating the click as a drag start.
            className="nodrag -my-1 size-6 opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
            aria-label={__('Delete step', 'yay-automation')}
            onClick={remove}
          >
            <Trash2 />
          </Button>
        )
      }
    >
      {type === 'condition' ? (
        <>
          <AddNodeButton nodeId={id} branch="then" left={branchLeft.then} />
          <AddNodeButton nodeId={id} branch="otherwise" left={branchLeft.otherwise} />
        </>
      ) : (
        <AddNodeButton nodeId={id} left="50%" />
      )}
    </NodeCardView>
  );
}

/** "+" under a source handle. Hidden once that handle already has a child. */
function AddNodeButton({ nodeId, branch, left }: { nodeId: string; branch?: WorkflowBranch; left: string }) {
  const form = useWorkflowFormContext();
  // Subscribe to just this boolean so the button re-renders only when it flips.
  const occupied = useStore(form.store, (state) => hasChild(state.values.graph, nodeId, branch));

  if (occupied) return null;

  const add = (kind: 'condition' | 'action') =>
    form.setFieldValue('graph', (graph) => addNode(graph, nodeId, kind, branch));

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="outline"
          size="icon-sm"
          className="nodrag absolute top-full mt-3 size-6 -translate-x-1/2 rounded-full"
          style={{ left }}
          aria-label={__('Add step', 'yay-automation')}
          onClick={(event) => event.stopPropagation()}
        >
          <Plus />
        </Button>
      </DropdownMenuTrigger>
      {/* Portaled, but React events still bubble to the node; keep menu clicks from opening its settings. */}
      <DropdownMenuContent onClick={(event) => event.stopPropagation()}>
        <DropdownMenuItem onSelect={() => add('condition')}>
          <GitBranch />
          {kindMeta.condition.label}
        </DropdownMenuItem>
        <DropdownMenuItem onSelect={() => add('action')}>
          <Play />
          {kindMeta.action.label}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

export const nodeTypes = {
  trigger: WorkflowNodeCard,
  condition: WorkflowNodeCard,
  action: WorkflowNodeCard,
} satisfies Record<WorkflowNodeKind, unknown>;
