import type { MouseEvent, ReactNode } from 'react';
import { useStore } from '@tanstack/react-form';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import type { WorkflowBranch, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';
import { CircleAlert, GitBranch, Play, Plus, Trash2, Zap, type LucideIcon } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { blocks } from '../blocks';
import { useEditorContext } from './editor.context';
import { useWorkflowFormContext } from './useWorkflowForm';
import type { FlowNode } from './workflow.flow';
import { addNode, hasChild } from './workflow.graph';

export const kindMeta: Record<WorkflowNodeKind, { label: string; icon: LucideIcon }> = {
  trigger: { label: __('Trigger', 'yay-automation'), icon: Zap },
  condition: { label: __('Condition', 'yay-automation'), icon: GitBranch },
  action: { label: __('Action', 'yay-automation'), icon: Play },
};

/** Where each condition branch leaves the card. Shared by the handles and their "+" buttons. */
const branchLeft: Record<WorkflowBranch, string> = { then: '30%', otherwise: '70%' };

/**
 * Card body + handles. No form access, so it also renders on the read-only template preview.
 * Header = block label, body = plain-language summary. `error` adds the red "Error" header above it.
 */
export function NodeCardView({
  type,
  data,
  selected,
  error,
  className,
  actions,
  children,
}: {
  type: WorkflowNodeKind;
  data: WorkflowNodeData;
  selected?: boolean;
  error?: boolean;
  className?: string;
  /** Extra controls in the header row, after the kind icon. */
  actions?: ReactNode;
  children?: ReactNode;
}) {
  const { label: kindLabel, icon: Icon } = kindMeta[type];
  const block = blocks[data.block];

  return (
    <div className={cn('group relative w-60', className)}>
      {type !== 'trigger' && <Handle type="target" position={Position.Top} />}

      {/* Out of flow, tucked under the card's top edge: showing or hiding it never moves the card or
          its handles. The opaque base keeps the tint readable over the canvas and the incoming edge. */}
      {error && (
        <div className="bg-card absolute inset-x-0 bottom-full -mb-2 overflow-hidden rounded-t-lg">
          <div className="bg-destructive/15 text-destructive flex items-center gap-1.5 px-3 pt-1.5 pb-3.5 text-xs font-medium">
            <CircleAlert className="size-3.5" aria-hidden />
            {__('Error', 'yay-automation')}
          </div>
        </div>
      )}

      <div
        className={cn(
          'bg-card text-card-foreground relative rounded-lg border p-3 shadow-sm',
          selected ? 'border-primary' : error ? 'border-destructive/40' : 'border-border',
        )}
      >
        <div className="flex flex-col items-start gap-1.5">
          <div className="text-muted-foreground flex items-center gap-1.5 text-xs">
            <Icon className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-label={kindLabel} />
            {kindLabel}
          </div>
          <div className="flex items-center gap-1.5">
            <div className="min-w-0 flex-1 font-medium">{block.label}</div>
          </div>
        </div>
        <div className="text-muted-foreground mt-1 text-xs">{block.summary(data.config)}</div>
      </div>
      {actions && <div className="absolute top-1/2 left-full ml-1.5 -translate-y-1/2">{actions}</div>}
      {type === 'condition' ? (
        <>
          <Handle id="then" type="source" position={Position.Bottom} style={{ left: branchLeft.then }} />
          <Handle id="otherwise" type="source" position={Position.Bottom} style={{ left: branchLeft.otherwise }} />
        </>
      ) : (
        <Handle type="source" position={Position.Bottom} />
      )}

      {children}
    </div>
  );
}

/**
 * Editable card: adds delete and "+" controls on top of the view. `nodeTypes` maps each kind here.
 * Clicking the card opens its settings (the canvas's `onNodeClick`), so the controls stop propagation.
 */
export function WorkflowNodeCard({ id, type, data, selected }: NodeProps<FlowNode>) {
  const { openNodeId, errorNodeIds, requestDelete } = useEditorContext();

  const remove = (event: MouseEvent) => {
    event.stopPropagation();
    requestDelete([id]);
  };

  return (
    <NodeCardView
      type={type}
      data={data}
      selected={selected || openNodeId === id}
      error={errorNodeIds.has(id)}
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

function ReadOnlyNodeCard({ type, data }: NodeProps<FlowNode>) {
  return <NodeCardView type={type} data={data} />;
}

export const readOnlyNodeTypes = {
  trigger: ReadOnlyNodeCard,
  condition: ReadOnlyNodeCard,
  action: ReadOnlyNodeCard,
} satisfies Record<WorkflowNodeKind, unknown>;
