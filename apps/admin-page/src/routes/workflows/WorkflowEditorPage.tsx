import { ArrowLeft } from 'lucide-react';
import { Link, useParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkflowQuery } from './editor/workflow.query';
import { WorkflowCanvas } from './editor/WorkflowCanvas';

export default function WorkflowEditorPage() {
  const { workflowId = '' } = useParams();
  const { data: workflow, isPending, error } = useWorkflowQuery(workflowId);

  return (
    // Fill the viewport below the WP admin bar (32px) and plugin header (h-13.5).
    <div className="flex h-[calc(100vh-32px-3.375rem)] flex-col">
      <div className="bg-background flex h-12 shrink-0 items-center gap-3 border-t px-4">
        <Button variant="ghost" size="icon-sm" asChild>
          <Link to="/workflows" aria-label={__('Back to workflows', 'yay-automation')}>
            <ArrowLeft />
          </Link>
        </Button>
        {workflow ? (
          <>
            <span className="font-medium">{workflow.name}</span>
            <Badge variant="secondary">{workflow.status}</Badge>
          </>
        ) : (
          <Skeleton className="h-5 w-40" />
        )}
      </div>

      <div className="min-h-0 flex-1 border-t">
        {isPending && <Skeleton className="size-full rounded-none" />}
        {error && (
          <div className="text-muted-foreground flex size-full items-center justify-center">{error.message}</div>
        )}
        {workflow && <WorkflowCanvas graph={workflow.graph} />}
      </div>
    </div>
  );
}
