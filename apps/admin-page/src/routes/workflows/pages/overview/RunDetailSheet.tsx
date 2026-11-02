import { useEffect, useState } from 'react';
import type { RunDetail, StepId, WorkflowNodeKind } from '@yaymatik/types';
import type { LucideIcon } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { shortDayTime } from '@/lib/helpers/date.helper';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { ApiError } from '../../api/workflow.api';
import { useRunQuery } from '../../api/workflow.query';
import { RunStatusBadge } from '../../shared/ui/RunStatus';
import { steps } from '../../steps';
import { StepTile } from '../../steps/core/StepTile';
import { stepResult } from './runResults';

/** One run of this workflow from the right; a missing run or another workflow's closes with "Run not found.". */
export function RunDetailSheet({
  workflowId,
  runId,
  onClose,
}: {
  workflowId: string;
  runId: string | null;
  onClose: () => void;
}) {
  const { data: run, error } = useRunQuery(workflowId, runId);
  const notFound = !!runId && (run ? run.workflowId !== workflowId : error instanceof ApiError && error.status === 404);

  useEffect(() => {
    if (!notFound) return;
    // The id keeps one toast when the effect runs twice (React's development mode).
    toast.error(__('Run not found.', 'yaymatik'), { id: 'run-not-found' });
    onClose();
  }, [notFound, onClose]);

  // Keeps the last run on screen while the sheet slides closed (`runId` is already null by then).
  const [lastShown, setLastShown] = useState<RunDetail | null>(null);
  if (run && !notFound && run !== lastShown) setLastShown(run);
  const shown = runId ? (run && !notFound ? run : null) : lastShown;

  return (
    <Sheet open={!!runId && !notFound} onOpenChange={(open) => !open && onClose()}>
      <SheetContent className="gap-0">
        <SheetHeader className="pe-12">
          <SheetTitle className="leading-6">
            {shown ? (
              shown.subjectUrl ? (
                <a href={shown.subjectUrl} className="text-link hover:underline">
                  {shown.subjectLabel}
                </a>
              ) : (
                shown.subjectLabel
              )
            ) : (
              <Skeleton className="h-5 w-32" />
            )}
          </SheetTitle>
          <SheetDescription asChild>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
              {shown ? (
                <>
                  <RunStatusBadge run={shown} />
                  <RunTimes run={shown} />
                </>
              ) : (
                <Skeleton className="h-4 w-48" />
              )}
            </div>
          </SheetDescription>
        </SheetHeader>
        <div className="min-h-0 flex-1 overflow-y-auto p-5">
          {shown ? (
            <RunSteps run={shown} />
          ) : error ? (
            <p className="text-muted-foreground text-sm">{error.message}</p>
          ) : (
            <Skeleton className="h-40 w-full" />
          )}
          {/* Why the run ended, unless a step already says it (e.g. cancelled before its next step). */}
          {shown?.error && !shown.steps.some((step) => step.error === shown.error) && (
            <p className="text-muted-foreground mt-4 text-sm">{shown.error}</p>
          )}
          {/* Its own line: the reason may sit on a step's row or below the steps. */}
          {shown?.stoppedByOrderUrl && (
            <a href={shown.stoppedByOrderUrl} className="text-link mt-3 block text-sm hover:underline">
              {__('View order', 'yaymatik')}
            </a>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}

function RunTimes({ run }: { run: RunDetail }) {
  return (
    <span>
      {run.startedAt &&
        sprintf(
          /* translators: %s: date and time, e.g. "Oct 9 at 3:00 PM" */
          __('Started %s', 'yaymatik'),
          shortDayTime(run.startedAt),
        )}
      {run.finishedAt && (
        <>
          {' · '}
          {sprintf(
            /* translators: %s: date and time, e.g. "Oct 9 at 3:00 PM" */
            __('Finished %s', 'yaymatik'),
            shortDayTime(run.finishedAt),
          )}
        </>
      )}
    </span>
  );
}

/** The trigger, then each step that ran; steps not reached aren't listed. */
function RunSteps({ run }: { run: RunDetail }) {
  const trigger = steps[run.triggerId as StepId] as (typeof steps)[StepId] | undefined;
  return (
    <ol className="flex flex-col gap-4">
      <StepLine kind="trigger" icon={trigger?.icon} label={trigger?.label ?? run.triggerId} time={run.startedAt} />
      {run.steps.map((step) => {
        const stepDef = step.stepType ? steps[step.stepType] : undefined;
        return (
          <StepLine
            key={step.id}
            kind={stepDef?.kind ?? 'action'}
            icon={stepDef?.icon}
            empty={!stepDef}
            label={stepDef?.label ?? __('Removed step', 'yaymatik')}
            result={stepResult(step, run)}
            time={step.finishedAt ?? step.startedAt}
          />
        );
      })}
    </ol>
  );
}

function StepLine({
  kind,
  icon,
  empty,
  label,
  result,
  time,
}: {
  kind: WorkflowNodeKind;
  icon?: LucideIcon;
  empty?: boolean;
  label: string;
  result?: string;
  time: string | null;
}) {
  return (
    <li className="flex items-start gap-3">
      <StepTile kind={kind} icon={icon} empty={empty} />
      <div className="min-w-0 flex-1">
        <div className="flex items-baseline justify-between gap-3">
          <span className="truncate text-sm font-medium">{label}</span>
          {time && <span className="text-muted-foreground shrink-0 text-xs">{shortDayTime(time)}</span>}
        </div>
        {result && <p className="text-muted-foreground-600 m-0 text-[13px] wrap-break-word">{result}</p>}
      </div>
    </li>
  );
}
