import { __, sprintf } from '@wordpress/i18n';

import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Skeleton } from '@/components/ui/skeleton';
import { WorkflowRunsIllustration } from './WorkflowRunsIllustration';

// Stands in for the link while splitting the sentence, so translations may use `%s` or `%1$s`.
const LINK_SLOT = '\u0000';

/** One execution of a workflow. Runs aren't logged yet; the fields come with the runs table. */
export interface WorkflowRun {
  id: string;
}

/**
 * Runs of a workflow. Only the empty state exists for now: run logging doesn't, so callers pass an
 * empty list. The runs table goes where `runs` has items.
 */
export function WorkflowRuns({ runs, loading = false }: { runs: WorkflowRun[]; loading?: boolean }) {
  if (loading) {
    return <Skeleton className="h-64 w-full" />;
  }
  if (runs.length > 0) {
    return null;
  }

  const [before, after = ''] = sprintf(
    /* translators: %s: link text, "monitoring workflow runs" */
    __('Learn more about %s', 'yay-automation'),
    LINK_SLOT,
  ).split(LINK_SLOT);

  return (
    <Empty className="gap-6 border-0 py-12">
      <EmptyHeader className="max-w-2xl">
        <EmptyMedia>
          <WorkflowRunsIllustration />
        </EmptyMedia>
        <EmptyTitle className="text-lg font-semibold">
          {__('Workflow runs will show up here', 'yay-automation')}
        </EmptyTitle>
        <EmptyDescription>
          {__('When the workflow is triggered by an event, its results will appear here.', 'yay-automation')}
          <br />
          {__('Make sure your workflow is set up and active to see workflow runs.', 'yay-automation')}
        </EmptyDescription>
      </EmptyHeader>
      <EmptyContent>
        <EmptyDescription className="text-foreground">
          {before}
          <a href={window.yayAutomationMeta.docs.workflowRuns} target="_blank" rel="noreferrer">
            {__('monitoring workflow runs', 'yay-automation')}
          </a>
          {after}
        </EmptyDescription>
      </EmptyContent>
    </Empty>
  );
}
