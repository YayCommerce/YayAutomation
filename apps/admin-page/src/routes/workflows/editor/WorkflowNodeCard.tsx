import { Handle, Position, type NodeProps } from '@xyflow/react';
import type { WorkflowNodeKind } from '@yay-automation/types';
import { GitBranch, Play, Zap, type LucideIcon } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import type { FlowNode } from './workflow.flow';

const kindMeta: Record<WorkflowNodeKind, { label: string; icon: LucideIcon }> = {
  trigger: { label: __('Trigger', 'yay-automation'), icon: Zap },
  condition: { label: __('Condition', 'yay-automation'), icon: GitBranch },
  action: { label: __('Action', 'yay-automation'), icon: Play },
};

/** One component for every kind; `nodeTypes` maps each kind here. */
export function WorkflowNodeCard({ type, data, selected }: NodeProps<FlowNode>) {
  const { label, icon: Icon } = kindMeta[type];

  return (
    <div
      className={cn(
        'bg-card text-card-foreground w-60 rounded-lg border p-3 shadow-sm',
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
          <Handle id="then" type="source" position={Position.Bottom} style={{ left: '30%' }} />
          <Handle id="otherwise" type="source" position={Position.Bottom} style={{ left: '70%' }} />
        </>
      ) : (
        <Handle type="source" position={Position.Bottom} />
      )}
    </div>
  );
}

export const nodeTypes = {
  trigger: WorkflowNodeCard,
  condition: WorkflowNodeCard,
  action: WorkflowNodeCard,
} satisfies Record<WorkflowNodeKind, unknown>;
