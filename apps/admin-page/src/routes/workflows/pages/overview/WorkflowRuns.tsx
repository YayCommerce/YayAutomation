import { useEffect, useState } from 'react';
import type { RunListItem, RunStatus } from '@yaymatik/types';
import { Activity } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { shortDayTime } from '@/lib/helpers/date.helper';
import { cn } from '@/lib/utils';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Pagination } from '@/components/ui/pagination';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useWorkflowRunsQuery } from '../../api/workflow.query';
import { RunStatusBadge } from '../../shared/ui/RunStatus';
import { tabBarClass, tabClass } from '../../shared/ui/tabs';

const filters: { value: string; label: string; statuses: RunStatus[] }[] = [
  { value: 'all', label: __('All', 'yaymatik'), statuses: [] },
  { value: 'running', label: __('Running', 'yaymatik'), statuses: ['queued', 'running'] },
  { value: 'waiting', label: __('Waiting', 'yaymatik'), statuses: ['waiting'] },
  { value: 'completed', label: __('Completed', 'yaymatik'), statuses: ['completed'] },
  { value: 'failed', label: __('Failed', 'yaymatik'), statuses: ['failed'] },
  { value: 'cancelled', label: __('Cancelled', 'yaymatik'), statuses: ['cancelled'] },
  { value: 'skipped', label: __('Skipped', 'yaymatik'), statuses: ['skipped'] },
];

const COLUMNS = 4;

/** The "Workflow runs" section below the tabs: a status filter and one page of runs, newest first. */
export function WorkflowRuns({ workflowId, onOpen }: { workflowId: string; onOpen: (runId: string) => void }) {
  const [filter, setFilter] = useState(filters[0]);
  const [page, setPage] = useState(1);
  const { data, error, isPending, isPlaceholderData } = useWorkflowRunsQuery(workflowId, page, filter.statuses);
  const pageCount = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1;
  // Runs ended or were cancelled since the page was opened, so it's now past the last one: step back.
  const pastLast = !!data && !isPlaceholderData && page > pageCount;
  useEffect(() => {
    if (pastLast) setPage(pageCount);
  }, [pastLast, pageCount]);
  // Never ran: the empty state, without a filter that can't match anything.
  const neverRan = !!data && !isPlaceholderData && data.total === 0 && filter.value === 'all';

  return (
    <section className="flex flex-col gap-2">
      {/* The cards' p-3, so the title lines up with their content. */}
      <h2 className="px-3 text-sm font-semibold">{__('Workflow runs', 'yaymatik')}</h2>
      {neverRan ? (
        <NoRuns />
      ) : (
        <div className="bg-card flex flex-col gap-3 rounded-lg border p-3 shadow-xs">
          <Segmented
            value={filter.value}
            onValueChange={(value) => {
              const next = filters.find((f) => f.value === value);
              if (!next) return;
              setFilter(next);
              setPage(1);
            }}
            shape="square"
            className={cn(tabBarClass, 'w-fit')}
          >
            {filters.map((f) => (
              <SegmentedItem key={f.value} value={f.value} className={tabClass}>
                {f.label}
              </SegmentedItem>
            ))}
          </Segmented>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{__('Started', 'yaymatik')}</TableHead>
                  <TableHead>{__('Subject', 'yaymatik')}</TableHead>
                  <TableHead>{__('Status', 'yaymatik')}</TableHead>
                  <TableHead className="text-right">{__('Steps run', 'yaymatik')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {isPending ? (
                  Array.from({ length: 3 }, (_, i) => (
                    <TableRow key={i}>
                      {Array.from({ length: COLUMNS }, (_, j) => (
                        <TableCell key={j}>
                          <Skeleton className="h-4 w-28" />
                        </TableCell>
                      ))}
                    </TableRow>
                  ))
                ) : !data ? (
                  <TableRow>
                    <TableCell colSpan={COLUMNS} className="text-muted-foreground">
                      {error?.message}
                    </TableCell>
                  </TableRow>
                ) : data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={COLUMNS} className="text-muted-foreground h-24 text-center">
                      {__('No runs with this status.', 'yaymatik')}
                    </TableCell>
                  </TableRow>
                ) : (
                  data.items.map((run) => <RunRow key={run.id} run={run} onOpen={() => onOpen(run.id)} />)
                )}
              </TableBody>
            </Table>
          </div>
          {pageCount > 1 && (
            <Pagination
              pageIndex={page - 1}
              pageCount={pageCount}
              onPreviousPage={() => setPage(page - 1)}
              onNextPage={() => setPage(page + 1)}
              onPageChange={(index) => setPage(index + 1)}
              canPreviousPage={page > 1}
              canNextPage={page < pageCount}
              disabled={isPlaceholderData}
              className="text-sm sm:ms-auto"
            />
          )}
        </div>
      )}
    </section>
  );
}

/** The whole row opens the run's detail; the start time is its button for the keyboard, the subject links to the order. */
function RunRow({ run, onOpen }: { run: RunListItem; onOpen: () => void }) {
  return (
    <TableRow
      className="cursor-pointer"
      onClick={(event) => {
        if (!(event.target as HTMLElement).closest('a, button')) onOpen();
      }}
    >
      <TableCell className="whitespace-nowrap">
        <button type="button" className="hover:underline" onClick={onOpen}>
          {run.startedAt && shortDayTime(run.startedAt)}
        </button>
      </TableCell>
      <TableCell>
        {run.subjectUrl ? (
          <a href={run.subjectUrl} className="text-link hover:underline">
            {run.subjectLabel}
          </a>
        ) : (
          run.subjectLabel
        )}
      </TableCell>
      <TableCell>
        <div className="flex flex-col items-start gap-1">
          <RunStatusBadge run={run} />
          {run.error && <span className="text-muted-foreground text-xs">{run.error}</span>}
        </div>
      </TableCell>
      <TableCell className="text-right tabular-nums">{run.stepsRun}</TableCell>
    </TableRow>
  );
}

function NoRuns() {
  return (
    <Empty className="gap-3 border-0 py-8">
      <EmptyHeader className="max-w-lg">
        {/* The Health check panel's circle; a card tile, so it shows on the section's grey. */}
        <EmptyMedia className="bg-card text-muted-foreground mb-0 size-11 rounded-full border shadow-xs">
          <Activity className="size-5" aria-hidden />
        </EmptyMedia>
        <EmptyTitle className="text-base font-semibold">{__('No runs yet', 'yaymatik')}</EmptyTitle>
        <EmptyDescription className="text-[13px]">
          {__('Each time this workflow starts, the run shows up here with every step’s result.', 'yaymatik')}
        </EmptyDescription>
      </EmptyHeader>
    </Empty>
  );
}
