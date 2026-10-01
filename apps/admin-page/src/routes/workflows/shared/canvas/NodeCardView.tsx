import type { ReactNode } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import type { WorkflowBranch, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';
import { CircleAlert, GitBranch, Info, Play, Zap, type LucideIcon } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { blocks } from '../../blocks';
import type { FlowNode } from './flow';

/**
 * How a step with issues is marked. `review` (blue) until Turn on or Apply fails on it, then `error`
 * (red) for the rest of the editing session.
 */
export type IssueMode = 'review' | 'error';

export const kindMeta: Record<WorkflowNodeKind, { label: string; icon: LucideIcon }> = {
  trigger: { label: __('Trigger', 'yay-automation'), icon: Zap },
  condition: { label: __('Condition', 'yay-automation'), icon: GitBranch },
  action: { label: __('Action', 'yay-automation'), icon: Play },
};

/** Where each condition branch leaves the card. Shared by the handles and their "+" buttons. */
export const branchLeft: Record<WorkflowBranch, string> = { then: '30%', otherwise: '70%' };

/**
 * Card body + handles. No form access, so it also renders on the read-only template preview.
 * Header = block label, body = plain-language summary. `issue` adds a blue "Review" or red "Error"
 * header above it.
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
  issue?: IssueMode;
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
      {issue && (
        <div className="bg-card absolute inset-x-0 bottom-full -mb-2 overflow-hidden rounded-t-lg">
          {issue === 'error' ? (
            <div className="bg-destructive/15 text-destructive flex items-center gap-1.5 px-3 pt-1.5 pb-3.5 text-xs font-medium">
              <CircleAlert className="size-3.5" aria-hidden />
              {__('Error', 'yay-automation')}
            </div>
          ) : (
            <div className="bg-primary/15 text-primary flex items-center gap-1.5 px-3 pt-1.5 pb-3.5 text-xs font-medium">
              <Info className="size-3.5" aria-hidden />
              {__('Review', 'yay-automation')}
            </div>
          )}
        </div>
      )}

      <div
        className={cn(
          'bg-card text-card-foreground relative rounded-lg border p-3 shadow-sm',
          selected
            ? 'border-primary'
            : issue === 'error'
              ? 'border-destructive/40'
              : issue === 'review'
                ? 'border-primary/40'
                : 'border-border',
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

function ReadOnlyNodeCard({ type, data }: NodeProps<FlowNode>) {
  return <NodeCardView type={type} data={data} />;
}

export const readOnlyNodeTypes = {
  trigger: ReadOnlyNodeCard,
  condition: ReadOnlyNodeCard,
  action: ReadOnlyNodeCard,
} satisfies Record<WorkflowNodeKind, unknown>;
