import type { ReactNode } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import type { WorkflowBranch, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';
import { CircleAlert, GitBranch, Play, Zap, type LucideIcon } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { blockOf } from '../../blocks';
import { sourceHandleStyle, targetHandleStyle, type FlowNode } from './flow';

export const kindMeta: Record<WorkflowNodeKind, { label: string; icon: LucideIcon; choose: string }> = {
  trigger: { label: __('Trigger', 'yay-automation'), icon: Zap, choose: __('Select a trigger', 'yay-automation') },
  condition: {
    label: __('Condition', 'yay-automation'),
    icon: GitBranch,
    choose: __('Select condition', 'yay-automation'),
  },
  action: { label: __('Action', 'yay-automation'), icon: Play, choose: __('Select an action', 'yay-automation') },
};

/** Where each condition branch leaves the card. Shared by the handles and their "+" buttons. */
export const branchLeft: Record<WorkflowBranch, string> = { then: '30%', otherwise: '70%' };

/**
 * Card body + handles. No form access, so it also renders on the read-only template preview.
 * Header = block label + kind icon, body = plain-language summary. `issue` adds a red "Error" header above it.
 */
export function NodeCardView({
  type,
  data,
  selected,
  issue,
  className,
  actions,
  children,
}: {
  type: WorkflowNodeKind;
  data: WorkflowNodeData;
  selected?: boolean;
  issue?: boolean;
  className?: string;
  /** Extra controls in the header row, after the kind icon. */
  actions?: ReactNode;
  children?: ReactNode;
}) {
  const { label: kindLabel, icon: Icon, choose } = kindMeta[type];
  const block = blockOf(data);

  return (
    <div className={cn('group relative w-70', className)}>
      {type !== 'trigger' && <Handle type="target" position={Position.Top} style={targetHandleStyle} />}

      {/* Out of flow, tucked under the card's top edge: showing or hiding it never moves the card or
          its handles. The opaque base keeps the tint readable over the canvas and the incoming edge. */}
      {issue && (
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
          selected ? 'border-primary' : issue ? 'border-destructive/40' : 'border-border',
        )}
      >
        <div className="flex items-start gap-2">
          <div className="min-w-0 flex-1 text-sm font-semibold">{block?.label ?? kindLabel}</div>
          <Icon className="text-muted-foreground mt-0.5 size-4 shrink-0" role="img" aria-label={kindLabel} />
        </div>
        {/* Not chosen yet: the kind's prompt, muted. Summaries may span lines (a condition's criteria). */}
        <div className={cn('mt-2 text-sm whitespace-pre-line', !block && 'text-muted-foreground')}>
          {block ? block.summary(data.config) : choose}
        </div>
      </div>
      {actions && <div className="absolute top-1/2 left-full ml-1.5 -translate-y-1/2">{actions}</div>}
      {type === 'condition' ? (
        <>
          <Handle
            id="then"
            type="source"
            position={Position.Bottom}
            style={{ ...sourceHandleStyle, left: branchLeft.then }}
          />
          <Handle
            id="otherwise"
            type="source"
            position={Position.Bottom}
            style={{ ...sourceHandleStyle, left: branchLeft.otherwise }}
          />
        </>
      ) : (
        <Handle type="source" position={Position.Bottom} style={sourceHandleStyle} />
      )}

      {children}
    </div>
  );
}

function ReadOnlyNodeCard({ type, data }: NodeProps<FlowNode>) {
  return <NodeCardView type={type} data={data} />;
}

export const readOnlyNodeTypes = {
  trigger: ReadOnlyNodeCard,
  condition: ReadOnlyNodeCard,
  action: ReadOnlyNodeCard,
} satisfies Record<WorkflowNodeKind, unknown>;
