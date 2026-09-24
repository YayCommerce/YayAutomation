import { useParams } from 'react-router';

import { Skeleton } from '@/components/ui/skeleton';
import { EditorLayout } from './editor/EditorLayout';
import { useWorkflowQuery } from './editor/workflow.query';
import { WorkflowEditor } from './editor/WorkflowEditor';

export default function WorkflowEditorPage() {
  const { workflowId = '' } = useParams();
  const { data: workflow, error } = useWorkflowQuery(workflowId);

  if (workflow) {
    return <WorkflowEditor key={workflow.id} workflow={workflow} />;
  }

  return (
    <EditorLayout toolbar={<Skeleton className="h-5 w-40" />}>
      {error ? (
        <div className="text-muted-foreground flex size-full items-center justify-center">{error.message}</div>
      ) : (
        <Skeleton className="size-full rounded-none" />
      )}
    </EditorLayout>
  );
}
