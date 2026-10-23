import type { RunStatus, Workflow } from '@yaymatik/types';
import { Link } from 'react-router';
import { humanTimeDiff } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

import { formatDateTime } from '@/lib/helpers/date.helper';
import { cn } from '@/lib/utils';

/** A queued run shows as Running: to the store owner it has started. */
export const runStatusLabels: Record<RunStatus, string> = {
  queued: __('Running', 'yaymatik'),
  running: __('Running', 'yaymatik'),
  waiting: __('Waiting', 'yaymatik'),
  completed: __('Completed', 'yaymatik'),
  failed: __('Failed', 'yaymatik'),
  cancelled: __('Cancelled', 'yaymatik'),
  skipped: __('Skipped', 'yaymatik'),
};

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
