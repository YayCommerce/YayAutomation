import { useContext, type ReactNode } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import type { WorkflowBranch, WorkflowNodeData, WorkflowNodeKind } from '@yaymatik/types';
import { Check, CircleAlert, X, type LucideIcon } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { CARD_WIDTH } from '../../model/graph';
import { plainSummary, stepOf, SummaryText } from '../../steps';
import { kindIcons, StepTile } from '../../steps/core/StepTile';
import { StepEyebrow } from '../ui/StepTile';
import { CanvasTriggerContext, sourceHandleStyle, targetHandleStyle, type FlowNode } from './flow';

/**
 * How a step card marks an issue: amber "Needs setup" (its panel was left unfinished) or red "Error"
 * (after a failed Activate / Update). Same values as the panel's `Feedback`.
 */
export type IssueMode = 'review' | 'error';

export const kindMeta: Record<WorkflowNodeKind, { label: string; icon: LucideIcon; choose: string }> = {
  trigger: {
    label: __('Trigger', 'yaymatik'),
    icon: kindIcons.trigger,
    choose: __('Choose a trigger', 'yaymatik'),
  },
  condition: {
    label: __('Condition', 'yaymatik'),
    icon: kindIcons.condition,
    choose: __('Choose a condition', 'yaymatik'),
  },
  action: {
    label: __('Action', 'yaymatik'),
    icon: kindIcons.action,
    choose: __('Choose an action', 'yaymatik'),
  },
};

/** Where each condition branch leaves the card. Shared by the handles, their Yes / No and "+" buttons. */
export const branchLeft: Record<WorkflowBranch, string> = { then: '30%', otherwise: '70%' };

/**
 * Card body + handles. No form access, so it also renders on the read-only template preview.
 * Tile, then an eyebrow (kind · plugin), the step's name and a one-line summary. Not chosen yet: a
 * dashed card that asks for the step. `issue` adds a badge on the top-right corner.
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
  /** Extra controls on the card's right side. */
  actions?: ReactNode;
  children?: ReactNode;
}) {
  const { label: kindLabel, choose } = kindMeta[type];
  const trigger = useContext(CanvasTriggerContext);
  const stepDef = stepOf(data);
  const summary = stepDef?.summary(data.config, trigger);

  return (
    <div className={cn('group relative', className)} style={{ width: CARD_WIDTH }}>
      {type !== 'trigger' && <Handle type="target" position={Position.Top} style={targetHandleStyle} />}

      <div
        className={cn(
          'text-card-foreground relative flex min-h-21.5 flex-wrap items-start gap-x-3 rounded-xl border px-3.5 py-3',
          !stepDef ? 'bg-card/70 border-muted-foreground/40 border-dashed' : 'bg-card shadow-xs',
          // The step's plugin is off: greyed, still selectable and deletable.
          stepDef?.inactive && 'opacity-60 grayscale',
          // Selected: a primary ring, so the card keeps its size.
          selected
            ? 'border-primary ring-primary shadow-primary/30 border-solid shadow-lg ring-1'
            : issue === 'error'
              ? 'border-destructive/60'
              : issue === 'review'
                ? 'border-warning-accent/70'
                : stepDef && 'border-border',
        )}
      >
        <StepTile kind={type} icon={stepDef?.icon} empty={!stepDef} size="lg" />
        <div className="flex min-w-0 flex-1 flex-col">
          <StepEyebrow kind={type} kindLabel={kindLabel} stepLabel={stepDef?.label} plugin={stepDef?.plugin} />
          {/* Name and summary are cut to one line; the tooltip has them in full. */}
          <div
            title={stepDef?.label}
            className={cn('truncate text-sm/5', stepDef ? 'font-semibold' : 'text-muted-foreground-600 font-medium')}
          >
            {stepDef?.label ?? choose}
          </div>
          {summary !== undefined && (
            <div
              title={plainSummary(summary)}
              className="text-muted-foreground-600 [&_em]:text-muted-foreground [&_strong]:text-card-foreground truncate text-[13px]/4.75"
            >
              <SummaryText text={summary} />
            </div>
          )}
        </div>
        {/* Yes / No right above their handles. */}
        {type === 'condition' && (
          <div className="relative mt-2.5 h-5.5 basis-full text-xs font-semibold" aria-hidden>
            <span className={branchPill} style={{ left: branchLeft.then }}>
              <Check className="text-kind-action size-3.25 stroke-[2.5]" />
              {__('Yes', 'yaymatik')}
            </span>
            <span className={branchPill} style={{ left: branchLeft.otherwise }}>
              <X className="text-muted-foreground size-3.25 stroke-[2.5]" />
              {__('No', 'yaymatik')}
            </span>
          </div>
        )}

        {/* On the corner, out of flow: showing it never moves the card or its handles. */}
        {issue && (
          <span
            className={cn(
              'absolute -top-2.5 right-3 inline-flex h-5 items-center gap-1 rounded-full px-2 text-[11.5px] font-semibold whitespace-nowrap shadow-xs',
              issue === 'error' ? 'bg-destructive text-destructive-foreground' : 'bg-warning text-warning-foreground',
            )}
          >
            <CircleAlert className="size-3 stroke-[2.5]" aria-hidden />
            {issue === 'error' ? __('Error', 'yaymatik') : __('Needs setup', 'yaymatik')}
          </span>
        )}
      </div>
      {/* Padding, not margin: the pointer crosses into the control without leaving the card's hover. */}
      {actions && <div className="absolute top-1/2 left-full -translate-y-1/2 pl-2">{actions}</div>}
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

const branchPill =
  'bg-card absolute inline-flex h-5.5 -translate-x-1/2 items-center gap-1 rounded-full border pr-2.25 pl-1.75';

function ReadOnlyNodeCard({ type, data }: NodeProps<FlowNode>) {
  return <NodeCardView type={type} data={data} />;
}

export const readOnlyNodeTypes = {
  trigger: ReadOnlyNodeCard,
  condition: ReadOnlyNodeCard,
  action: ReadOnlyNodeCard,
} satisfies Record<WorkflowNodeKind, unknown>;
