import { useParams } from 'react-router';

import { EditorPlaceholder } from './editor/EditorLayout';
import { useWorkflowQuery } from './editor/workflow.query';
import { WorkflowEditor } from './editor/WorkflowEditor';

export default function WorkflowEditorPage() {
  const { workflowId = '' } = useParams();
  const { data: workflow, error } = useWorkflowQuery(workflowId);

  if (workflow) {
    return <WorkflowEditor key={workflow.id} workflow={workflow} />;
  }

  return <EditorPlaceholder error={error} />;
}
