import { useLocation, useNavigate, useSearchParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { PageCard, PageTitle } from '@/components/widget/PageWidget';
import { useWorkflowsQuery } from '../../api/workflow.query';
import { readLibraryNavState, type LibraryNavState } from '../../shared/nav/library.nav';
import { WorkflowsEmptyIllustration } from './WorkflowIllustrations';
import { TemplatesDialog } from './library/TemplatesDialog';
import { WorkflowsTable, WorkflowsTableSkeleton } from './WorkflowsTable';

export default function WorkflowListPage() {
  const navigate = useNavigate();
  const { data: workflows, error } = useWorkflowsQuery();

  // Library open state lives in the URL (`?templates=list`) so reload and "back" from a preview reopen it.
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();
  const templatesOpen = searchParams.get('templates') === 'list';
  const setTemplatesOpen = (open: boolean) => {
    if (open) {
      // Pushed, so browser Back closes the library.
      setSearchParams((prev) => withTemplates(prev, true), {
        state: { libraryOpened: true } satisfies LibraryNavState,
      });
    } else if (readLibraryNavState(location.state).libraryOpened) {
      // Pop the entry we pushed rather than stacking a closed copy on top of it.
      navigate(-1);
    } else {
      // Landed here with `?templates=list` (link, reload): nothing to pop, so just drop the param.
      setSearchParams((prev) => withTemplates(prev, false), { replace: true });
    }
  };

  // Building a workflow from scratch isn't available yet; templates are the way in.
  const createButton = (
    <Tooltip>
      {/* Disabled buttons swallow pointer events, so the span carries the tooltip. */}
      <TooltipTrigger asChild>
        <span tabIndex={0}>
          <Button disabled>{__('Create workflow', 'yay-automation')}</Button>
        </span>
      </TooltipTrigger>
      <TooltipContent>{__('Coming soon', 'yay-automation')}</TooltipContent>
    </Tooltip>
  );

  return (
    <PageCard>
      <div className="flex flex-wrap items-center justify-between gap-4">
        <PageTitle>{__('Workflows', 'yay-automation')}</PageTitle>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={() => setTemplatesOpen(true)}>
            {__('Template Library', 'yay-automation')}
          </Button>
          {createButton}
        </div>
      </div>

      {error ? (
        <p className="text-destructive py-20 text-center text-sm">{error.message}</p>
      ) : !workflows ? (
        <div className="mt-6">
          <WorkflowsTableSkeleton />
        </div>
      ) : workflows.length > 0 ? (
        <div className="mt-6">
          <WorkflowsTable workflows={workflows} />
        </div>
      ) : (
        <Empty className="py-20">
          <EmptyHeader className="max-w-md">
            <EmptyMedia>
              <WorkflowsEmptyIllustration />
            </EmptyMedia>
            <EmptyTitle className="text-base">{__('Get more work done in less time', 'yay-automation')}</EmptyTitle>
            <EmptyDescription>
              {__('Turn your store tasks into automated workflows so you can get back to business.', 'yay-automation')}
            </EmptyDescription>
          </EmptyHeader>
          <EmptyContent className="flex-row justify-center">
            <Button variant="secondary" onClick={() => setTemplatesOpen(true)}>
              {__('Template Library', 'yay-automation')}
            </Button>
            {createButton}
          </EmptyContent>
        </Empty>
      )}

      <TemplatesDialog open={templatesOpen} onOpenChange={setTemplatesOpen} />
    </PageCard>
  );
}

function withTemplates(prev: URLSearchParams, open: boolean) {
  const params = new URLSearchParams(prev);
  if (open) params.set('templates', 'list');
  else params.delete('templates');
  return params;
}
