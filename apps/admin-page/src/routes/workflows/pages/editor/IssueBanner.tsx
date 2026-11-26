import { useState, type CSSProperties, type ReactNode } from 'react';
import type { WorkflowIssue, WorkflowNode } from '@yaymatik/types';
import { ChevronLeft, ChevronRight, CircleAlert } from 'lucide-react';
import { Link } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { kindMeta } from '../../shared/canvas/NodeCardView';
import { stepOf } from '../../steps';
import { StepTile } from '../../steps/core/StepTile';

const issueKey = (issue: WorkflowIssue) => `${issue.code}|${issue.nodeId ?? ''}`;

/** The message with the step's name in bold where it appears ("**Add order note** needs a note."). */
function withStepName(message: string, name: string): ReactNode {
  const at = name ? message.indexOf(name) : -1;
  if (at < 0) return message;
  return (
    <>
      {message.slice(0, at)}
      <strong className="font-semibold">{name}</strong>
      {message.slice(at + name.length)}
    </>
  );
}

/**
 * Error-mode bar: one issue at a time, with "1 / N" paging on the right.
 * A step issue shows the step's tile and message and links to the step; `unlinked` links to its
 * first step; `cart_tracking_off` links to Settings; `name_required` offers Rename; other
 * workflow-level issues (no trigger, no action) are just their message.
 */
export function IssueBanner({
  issues,
  nodeFor,
  onView,
  onRename,
  className,
  style,
}: {
  issues: WorkflowIssue[];
  /** The step an issue points at, if it's still in the graph. */
  nodeFor: (nodeId: string) => WorkflowNode | undefined;
  onView: (nodeId: string) => void;
  onRename: () => void;
  className?: string;
  style?: CSSProperties;
}) {
  // Follows the issue being read, not a position: fixing an earlier one mustn't swap the message.
  const [shown, setShown] = useState<{ key: string; index: number } | null>(null);
  if (!issues.length) return null;

  const found = shown ? issues.findIndex((issue) => issueKey(issue) === shown.key) : -1;
  // Gone (fixed) → the one now in its place, or the last.
  const current = found >= 0 ? found : Math.min(shown?.index ?? 0, issues.length - 1);
  const issue = issues[current];
  const stepId = issue.nodeId ?? issue.nodeIds?.[0];
  const show = (index: number) => setShown({ key: issueKey(issues[index]), index });

  const node = issue.nodeId ? nodeFor(issue.nodeId) : undefined;
  const stepDef = node && stepOf(node.data);
  const stepName = node ? (stepDef?.label ?? kindMeta[node.kind].label) : '';

  const link = (label: string, onClick: () => void) => (
    <Button variant="link" size="sm" className="h-auto p-0 align-baseline" onClick={onClick}>
      {label}
    </Button>
  );

  return (
    <div
      role="alert"
      className={cn(
        'bg-card text-card-foreground flex w-[min(48rem,calc(100%-2rem))] items-center gap-2.5 rounded-xl border px-2.5 py-2 text-sm shadow-md',
        className,
      )}
      style={style}
    >
      {node ? (
        <StepTile kind={node.kind} icon={stepDef?.icon} empty={!stepDef} size="sm" />
      ) : (
        <CircleAlert className="text-destructive ml-1 size-4 shrink-0" aria-hidden />
      )}
      <p className="min-w-0 flex-1">
        {withStepName(issue.message, stepName)}{' '}
        {issue.code === 'cart_tracking_off' ? (
          <Button variant="link" size="sm" className="h-auto p-0 align-baseline" asChild>
            <Link to="/settings">{__('Open Settings', 'yaymatik-automation-for-woocommerce')}</Link>
          </Button>
        ) : stepId ? (
          link(__('Show step', 'yaymatik-automation-for-woocommerce'), () => onView(stepId))
        ) : (
          issue.code === 'name_required' && link(__('Rename workflow', 'yaymatik-automation-for-woocommerce'), onRename)
        )}
      </p>
      {issues.length > 1 && (
        <div className="text-muted-foreground flex shrink-0 items-center gap-0.5 text-xs">
          <Button
            variant="ghost"
            size="icon-sm"
            className="size-6"
            disabled={current === 0}
            aria-label={__('Previous issue', 'yaymatik-automation-for-woocommerce')}
            onClick={() => show(current - 1)}
          >
            <ChevronLeft />
          </Button>
          <span className="tabular-nums">
            {sprintf(
              /* translators: 1: current issue number, 2: number of issues */
              __('%1$d / %2$d', 'yaymatik-automation-for-woocommerce'),
              current + 1,
              issues.length,
            )}
          </span>
          <Button
            variant="ghost"
            size="icon-sm"
            className="size-6"
            disabled={current === issues.length - 1}
            aria-label={__('Next issue', 'yaymatik-automation-for-woocommerce')}
            onClick={() => show(current + 1)}
          >
            <ChevronRight />
          </Button>
        </div>
      )}
    </div>
  );
}
