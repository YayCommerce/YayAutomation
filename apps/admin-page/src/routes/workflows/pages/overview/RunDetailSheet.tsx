import { useEffect, useMemo, useState } from 'react';
import type { RunDetail, StepId, WorkflowNodeKind } from '@yaymatik/types';
import { __ } from '@wordpress/i18n';

import { shortDayTime } from '@/lib/helpers/date.helper';
import { cn } from '@/lib/utils';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { ApiError } from '../../api/workflow.api';
import { useRunQuery } from '../../api/workflow.query';
import { RunStatusBadge } from '../../shared/ui/RunStatus';
import { stepOf, type Step } from '../../steps';
import { StepTile } from '../../steps/core/StepTile';
import { DeliveryStatus, type EmailDelivery } from './DeliveryStatus';
import { DRAFT_RUN_ID, draftRun } from './runDelivery.fixtures';
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
  const isDraft = runId === DRAFT_RUN_ID;
  const query = useRunQuery(workflowId, isDraft ? null : runId);
  const draft = useMemo(() => draftRun(workflowId), [workflowId]);
  const run = isDraft ? draft : query.data;
  const error = query.error;
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
        <SheetHeader className="flex-row items-center gap-3 pe-12">
          <SheetTitle className="min-w-0 truncate leading-6">
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
            <div className="shrink-0">{shown && <RunStatusBadge run={shown} />}</div>
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

/** The trigger, then each step that ran; steps not reached aren't listed. */
function RunSteps({ run }: { run: RunDetail }) {
  const trigger = stepNamed(run.triggerId);
  return (
    <ol className="flex flex-col gap-4">
      <StepLine
        kind="trigger"
        stepDef={trigger}
        label={trigger?.label ?? run.triggerId}
        result={run.facts.map((fact) => `${fact.label}: ${fact.value}`).join(', ')}
        time={run.startedAt}
      />
      {run.steps.map((step) => {
        const stepDef = step.stepType ? stepNamed(step.stepType) : undefined;
        return (
          <StepLine
            key={step.id}
            kind={stepDef?.kind ?? 'action'}
            stepDef={stepDef}
            label={stepDef?.label ?? __('Removed step', 'yaymatik')}
            result={stepResult(step, run)}
            delivery={step.output.delivery as EmailDelivery | undefined}
            time={step.finishedAt ?? step.startedAt}
          />
        );
      })}
    </ol>
  );
}

/** A step by id, as the canvas finds it: a step of a plugin that is off included. */
const stepNamed = (id: string) => stepOf({ block: id as StepId, config: {} });

function StepLine({
  kind,
  stepDef,
  label,
  result,
  delivery,
  time,
}: {
  kind: WorkflowNodeKind;
  stepDef: Step | undefined;
  label: string;
  result?: string;
  delivery?: EmailDelivery;
  time: string | null;
}) {
  return (
    // A row with no detail line centres its title on the tile; with one, both lines start at its top.
    <li className={cn('flex gap-3', result ? 'items-start' : 'items-center')}>
      <StepTile
        kind={kind}
        icon={stepDef?.icon}
        empty={!stepDef}
        className={stepDef?.inactive ? 'opacity-60 grayscale' : undefined}
      />
      <div className="min-w-0 flex-1">
        <div className="flex items-baseline justify-between gap-3">
          <span className="truncate text-sm font-medium">{label}</span>
          {time && <span className="text-muted-foreground shrink-0 text-xs">{shortDayTime(time)}</span>}
        </div>
        {result && <p className="text-muted-foreground-600 m-0 text-[13px] wrap-break-word">{result}</p>}
        {delivery && <DeliveryStatus delivery={delivery} />}
      </div>
    </li>
  );
}
