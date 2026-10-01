import { useParams } from 'react-router';

import { useWorkflowQuery } from '../../api/workflow.query';
import { EditorPlaceholder } from '../../shared/layout/EditorLayout';
import { WorkflowEditor } from './WorkflowEditor';

export default function WorkflowEditorPage() {
  const { workflowId = '' } = useParams();
  const { data: workflow, error } = useWorkflowQuery(workflowId);

  if (workflow) {
    return <WorkflowEditor key={workflow.id} workflow={workflow} />;
  }

  return <EditorPlaceholder error={error} />;
}
