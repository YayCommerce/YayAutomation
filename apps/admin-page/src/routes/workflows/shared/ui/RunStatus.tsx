import type { Run, RunStatus, Workflow } from '@yaymatik/types';
import { Link } from 'react-router';
import { humanTimeDiff } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

import { formatDateTime, shortDayTime } from '@/lib/helpers/date.helper';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { appBadge } from './WorkflowStatusBadge';

/** A queued run shows as Running: to the store owner it has started. */
const runStatusLabels: Record<RunStatus, string> = {
  queued: __('Running', 'yaymatik-automation-for-woocommerce'),
  running: __('Running', 'yaymatik-automation-for-woocommerce'),
  waiting: __('Waiting', 'yaymatik-automation-for-woocommerce'),
  completed: __('Completed', 'yaymatik-automation-for-woocommerce'),
  failed: __('Failed', 'yaymatik-automation-for-woocommerce'),
  cancelled: __('Cancelled', 'yaymatik-automation-for-woocommerce'),
  skipped: __('Skipped', 'yaymatik-automation-for-woocommerce'),
};

/** "Waiting until Oct 9 at 3:00 PM" for a waiting run; otherwise its status label. */
export const runStatusText = (run: Pick<Run, 'status' | 'waitUntil'>) =>
  run.status === 'waiting' && run.waitUntil
    ? sprintf(
        /* translators: %s: date and time, e.g. "Oct 9 at 3:00 PM" */
        __('Waiting until %s', 'yaymatik-automation-for-woocommerce'),
        shortDayTime(run.waitUntil),
      )
    : runStatusLabels[run.status];

const tones: Record<RunStatus, string> = {
  queued: 'bg-primary/14 text-link',
  running: 'bg-primary/14 text-link',
  waiting: 'bg-warning-soft text-warning-soft-foreground',
  completed: 'bg-success/15 text-success-soft-foreground',
  failed: 'bg-destructive/10 text-destructive',
  cancelled: 'bg-muted text-muted-foreground-600',
  skipped: 'bg-muted text-muted-foreground-600',
};

export function RunStatusBadge({ run }: { run: Pick<Run, 'status' | 'waitUntil'> }) {
  return (
    <Badge variant="secondary" className={cn(appBadge, tones[run.status])}>
      {runStatusText(run)}
    </Badge>
  );
}

/** Overview of one run: the workflow's page with the run's detail open. */
const runPath = (workflowId: string, runId: string) => `/workflows/${workflowId}?run=${runId}`;

/** "2 hours ago" (date and time on hover) over how that run stands, linked to it; null when it never ran. */
export function LastRun({ workflow, className }: { workflow: Workflow; className?: string }) {
  const { lastRunAt, lastRunStatus, lastRunId } = workflow;
  if (!lastRunAt || !lastRunStatus || !lastRunId) return null;
  return (
    <span className={cn('flex flex-col leading-tight', className)}>
      <span title={formatDateTime(lastRunAt)}>{humanTimeDiff(lastRunAt)}</span>
      <Link
        to={runPath(workflow.id, lastRunId)}
        className={cn(
          'w-fit text-xs font-normal hover:underline',
          lastRunStatus === 'failed' ? 'text-destructive' : 'text-muted-foreground',
        )}
      >
        {runStatusLabels[lastRunStatus]}
      </Link>
    </span>
  );
}
