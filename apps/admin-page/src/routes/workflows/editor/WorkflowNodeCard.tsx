import type { ReactNode } from 'react';
import { useStore } from '@tanstack/react-form';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import type { WorkflowBranch, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';
import { GitBranch, Play, Plus, Trash2, Zap, type LucideIcon } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useWorkflowFormContext } from './useWorkflowForm';
import type { FlowNode } from './workflow.flow';
import { addNode, hasChild, removeNode } from './workflow.graph';

export const kindMeta: Record<WorkflowNodeKind, { label: string; icon: LucideIcon }> = {
  trigger: { label: __('Trigger', 'yay-automation'), icon: Zap },
  condition: { label: __('Condition', 'yay-automation'), icon: GitBranch },
  action: { label: __('Action', 'yay-automation'), icon: Play },
};

/** Where each condition branch leaves the card. Shared by the handles and their "+" buttons. */
const branchLeft: Record<WorkflowBranch, string> = { then: '30%', otherwise: '70%' };

/** Card body + handles. No form access, so it also renders on the read-only template preview. */
export function NodeCardView({
  type,
  data,
  selected,
  children,
}: {
  type: WorkflowNodeKind;
  data: WorkflowNodeData;
  selected?: boolean;
  children?: ReactNode;
}) {
  const { label, icon: Icon } = kindMeta[type];

  return (
    <div
      className={cn(
        'group bg-card text-card-foreground relative w-60 rounded-lg border p-3 shadow-sm',
        selected ? 'border-primary' : 'border-border',
      )}
    >
      {type !== 'trigger' && <Handle type="target" position={Position.Top} />}

      <div className="text-muted-foreground flex items-center gap-1.5 text-xs">
        <Icon className="size-3.5" />
        {label}
      </div>
      <div className="mt-1 font-medium">{data.title}</div>
      {data.description && <div className="text-muted-foreground mt-0.5 text-xs">{data.description}</div>}

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

/** Editable card: adds delete and "+" controls on top of the view. `nodeTypes` maps each kind here. */
export function WorkflowNodeCard({ id, type, data, selected }: NodeProps<FlowNode>) {
  const form = useWorkflowFormContext();

  const remove = () => form.setFieldValue('graph', (graph) => removeNode(graph, id));

  return (
    <NodeCardView type={type} data={data} selected={selected}>
      {type !== 'trigger' && (
        <Button
          variant="ghost"
          size="icon-sm"
          // `nodrag` stops ReactFlow from treating the click as a drag start.
          className="nodrag absolute top-1.5 right-1.5 size-7 opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
          aria-label={__('Delete step', 'yay-automation')}
          onClick={remove}
        >
          <Trash2 />
        </Button>
      )}

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
        >
          <Plus />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent>
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
