import { useState } from 'react';
import { LayoutTemplate, Plus } from 'lucide-react';
import { useNavigate, useSearchParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { toast } from '@/components/ui/sonner';
import { PageCard, PageTitle } from '@/components/widget/PageWidget';
import { useCreateWorkflowMutation, useWorkflowsQuery } from '../../api/workflow.query';
import { CreateWorkflowDialog } from '../../shared/create/CreateWorkflowDialog';
import { TemplatesDialog } from './library/TemplatesDialog';
import { WorkflowsEmptyIllustration } from './WorkflowIllustrations';
import { WorkflowsTable, WorkflowsTableSkeleton } from './WorkflowsTable';

export default function WorkflowListPage() {
  const { data: workflows, error } = useWorkflowsQuery();

  // Library open state lives in the URL (`?templates=list`) so reload and "back" from a preview reopen it.
  const [searchParams, setSearchParams] = useSearchParams();
  const templatesOpen = searchParams.get('templates') === 'list';
  const setTemplatesOpen = (open: boolean) => {
    // Opening pushes, so browser Back closes the library. Closing always lands on the bare list:
    // popping with `navigate(-1)` could land on whatever came before (e.g. another `?templates=list`).
    setSearchParams((prev) => withTemplates(prev, open), { replace: !open });
  };

  // "Choose a trigger" saves a blank "New workflow" right away and opens it on its trigger picker.
  const [createOpen, setCreateOpen] = useState(false);
  const navigate = useNavigate();
  const createMutation = useCreateWorkflowMutation({
    onSaved: (workflow) => navigate(`/workflows/${workflow.id}/edit`),
    onFailed: (error) => toast.error(error.message),
  });
  const createButton = (
    <Button onClick={() => setCreateOpen(true)}>
      <Plus />
      {__('Create workflow', 'yaymatik-automation-for-woocommerce')}
    </Button>
  );
  const browseLabel = __('Browse templates', 'yaymatik-automation-for-woocommerce');

  return (
    <PageCard>
      <div className="flex min-h-9 flex-wrap items-center justify-between gap-4">
        <PageTitle>
          {__('Workflows', 'yaymatik-automation-for-woocommerce')}
          {!!workflows?.length && <span className="text-muted-foreground text-sm font-normal">{workflows.length}</span>}
        </PageTitle>
        {/* The empty state has its own pair; loading and errors offer none. */}
        {!!workflows?.length && (
          <div className="flex items-center gap-2">
            <Button variant="outline" onClick={() => setTemplatesOpen(true)}>
              <LayoutTemplate />
              {browseLabel}
            </Button>
            {createButton}
          </div>
        )}
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
            <EmptyTitle className="text-base">
              {__('Automate your store’s routine work', 'yaymatik-automation-for-woocommerce')}
            </EmptyTitle>
            <EmptyDescription>
              {__(
                'Start from a template or build your own. A workflow runs on its own once you activate it.',
                'yaymatik-automation-for-woocommerce',
              )}
            </EmptyDescription>
          </EmptyHeader>
          <EmptyContent className="flex-row justify-center">
            <Button variant="secondary" onClick={() => setTemplatesOpen(true)}>
              <LayoutTemplate />
              {browseLabel}
            </Button>
            {createButton}
          </EmptyContent>
        </Empty>
      )}

      <CreateWorkflowDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        creating={createMutation.isPending}
        onSelectTrigger={() => createMutation.mutate({})}
        onBrowseTemplates={() => {
          setCreateOpen(false);
          setTemplatesOpen(true);
        }}
      />
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
