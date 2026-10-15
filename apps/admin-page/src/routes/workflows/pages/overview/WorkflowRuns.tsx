import { Activity } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Skeleton } from '@/components/ui/skeleton';

// Stands in for the link while splitting the sentence, so translations may use `%s` or `%1$s`.
const LINK_SLOT = '\u0000';

/** One execution of a workflow. The fields come with the runs table (M2). */
export interface WorkflowRun {
  id: string;
}

/**
 * The "Workflow runs" section below the tabs. Only the empty state exists for now: the runs table
 * (M2) goes where `runs` has items.
 */
export function WorkflowRuns({ runs, loading = false }: { runs: WorkflowRun[]; loading?: boolean }) {
  const [before, after = ''] = sprintf(
    /* translators: %s: link text, "monitoring workflow runs" */
    __('Learn more about %s', 'yaymatik'),
    LINK_SLOT,
  ).split(LINK_SLOT);

  return (
    <section className="flex flex-col gap-2">
      <h2 className="text-sm font-semibold">{__('Workflow runs', 'yaymatik')}</h2>
      {loading ? (
        <Skeleton className="h-48 w-full" />
      ) : (
        runs.length === 0 && (
          <Empty className="gap-3 border-0 py-8">
            <EmptyHeader className="max-w-lg">
              <EmptyMedia className="bg-muted text-muted-foreground mb-1 flex h-13 w-18 items-center justify-center rounded-xl">
                <Activity className="size-6" strokeWidth={1.6} aria-hidden />
              </EmptyMedia>
              <EmptyTitle className="text-base font-semibold">{__('No runs yet', 'yaymatik')}</EmptyTitle>
              <EmptyDescription className="text-[13px]">
                {__(
                  'Each time an order starts this workflow, the run shows up here with every step’s result.',
                  'yaymatik',
                )}
              </EmptyDescription>
            </EmptyHeader>
            <EmptyContent>
              <EmptyDescription className="text-[13px]">
                {before}
                <a href={window.yayMatikMeta.docs.workflowRuns} target="_blank" rel="noreferrer">
                  {__('monitoring workflow runs', 'yaymatik')}
                </a>
                {after}
              </EmptyDescription>
            </EmptyContent>
          </Empty>
        )
      )}
    </section>
  );
}
