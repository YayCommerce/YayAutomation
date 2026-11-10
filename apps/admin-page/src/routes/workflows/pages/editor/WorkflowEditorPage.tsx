import { createPortal } from 'react-dom';
import { useParams } from 'react-router';

import { useHeaderSlot } from '@/routes/layout/useHeaderSlot';
import { useWorkflowQuery } from '../../api/workflow.query';
import { CreateWorkflowButton } from '../../shared/create/CreateWorkflowButton';
import { EditorPlaceholder } from '../../shared/layout/EditorLayout';
import { WorkflowEditor } from './WorkflowEditor';

export default function WorkflowEditorPage() {
  const { workflowId = '' } = useParams();
  const { data: workflow, error } = useWorkflowQuery(workflowId);
  const headerSlot = useHeaderSlot();

  return (
    <>
      {workflow ? (
        <WorkflowEditor key={workflow.id} workflow={workflow} />
      ) : (
        <>
          <EditorPlaceholder error={error} />
          {headerSlot && createPortal(<CreateWorkflowButton />, headerSlot)}
        </>
      )}
    </>
  );
}
