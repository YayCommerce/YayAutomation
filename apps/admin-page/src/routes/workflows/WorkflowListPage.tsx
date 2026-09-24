import { Plus, Workflow } from 'lucide-react';
import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { PageCard, PageTitle } from '@/components/widget/PageWidget';

export default function WorkflowListPage() {
  return (
    <PageCard>
      <div className="flex items-center justify-between gap-4">
        <PageTitle>{__('Workflows', 'yay-automation')}</PageTitle>
        <Button asChild>
          <Link to="/workflows/new">
            <Plus />
            {__('Create workflow', 'yay-automation')}
          </Link>
        </Button>
      </div>

      <Empty className="mt-6 border">
        <EmptyHeader>
          <EmptyMedia variant="icon">
            <Workflow />
          </EmptyMedia>
          <EmptyTitle>{__('No workflows yet', 'yay-automation')}</EmptyTitle>
          <EmptyDescription>
            {__('Automate tasks by connecting a trigger to conditions and actions.', 'yay-automation')}
          </EmptyDescription>
        </EmptyHeader>
        <EmptyContent>
          <Button variant="outline" asChild>
            <Link to="/workflows/new">{__('Create workflow', 'yay-automation')}</Link>
          </Button>
        </EmptyContent>
      </Empty>
    </PageCard>
  );
}
