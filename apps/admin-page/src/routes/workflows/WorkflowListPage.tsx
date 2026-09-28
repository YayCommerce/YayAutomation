import { useLocation, useNavigate, useSearchParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Button, LoadingButton } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { toast } from '@/components/ui/sonner';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { PageCard, PageTitle } from '@/components/widget/PageWidget';
import { blankGraph } from './editor/workflow.graph';
import { useCreateWorkflowMutation } from './editor/workflow.query';
import { readLibraryNavState, type LibraryNavState } from './templates/library.nav';
import { TemplatesDialog } from './templates/TemplatesDialog';
import { WorkflowsEmptyIllustration } from './WorkflowIllustrations';

export default function WorkflowListPage() {
  const navigate = useNavigate();
  const createMutation = useCreateWorkflowMutation();

  // Library open state lives in the URL (`?templates=1`) so reload and "back" from a preview reopen it.
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();
  const templatesOpen = searchParams.get('templates') === '1';
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
      // Landed here with `?templates=1` (link, reload): nothing to pop, so just drop the param.
      setSearchParams((prev) => withTemplates(prev, false), { replace: true });
    }
  };

  const createBlank = () =>
    createMutation.mutate(
      { name: __('Untitled workflow', 'yay-automation'), graph: blankGraph() },
      {
        onSuccess: (workflow) => navigate(`/workflows/${workflow.id}`),
        onError: (err) => toast.error(err.message),
      },
    );

  const createButton = (
    <LoadingButton loading={createMutation.isPending} disabled={createMutation.isPending} onClick={createBlank}>
      {__('Create workflow', 'yay-automation')}
    </LoadingButton>
  );

  return (
    <PageCard>
      <div className="flex flex-wrap items-center justify-between gap-4">
        <PageTitle>{__('Workflows', 'yay-automation')}</PageTitle>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={() => setTemplatesOpen(true)}>
            {__('Template Library', 'yay-automation')}
          </Button>
          <Tooltip>
            {/* Disabled buttons swallow pointer events, so the span carries the tooltip. */}
            <TooltipTrigger asChild>
              <span tabIndex={0}>
                <Button variant="outline" disabled>
                  {__('Import', 'yay-automation')}
                </Button>
              </span>
            </TooltipTrigger>
            <TooltipContent>{__('Coming soon', 'yay-automation')}</TooltipContent>
          </Tooltip>
          {createButton}
        </div>
      </div>

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

      <TemplatesDialog open={templatesOpen} onOpenChange={setTemplatesOpen} />
    </PageCard>
  );
}

function withTemplates(prev: URLSearchParams, open: boolean) {
  const params = new URLSearchParams(prev);
  if (open) params.set('templates', '1');
  else params.delete('templates');
  return params;
}
