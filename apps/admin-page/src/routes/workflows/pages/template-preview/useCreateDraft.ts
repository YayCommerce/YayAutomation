import type { WorkflowGraph } from '@yay-automation/types';
import { useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { toast } from '@/components/ui/sonner';
import { useCreateWorkflowMutation } from '../../api/workflow.query';

/** Creates an inactive draft right away and opens it in the editor, like Shopify Flow's Install. */
export function useCreateDraft() {
  const navigate = useNavigate();
  // Hook-level callbacks, not `mutate(…, { onSuccess })`: those are skipped if the page unmounts
  // mid-request (e.g. Back on the preview), which would create a draft with no feedback.
  const createMutation = useCreateWorkflowMutation({
    onSaved: (workflow) => {
      toast.success(__('Template installed', 'yay-automation'));
      navigate(`/workflows/${workflow.id}/edit`);
    },
    onFailed: (error) => toast.error(error.message),
  });

  const createDraft = (name: string, graph: WorkflowGraph) =>
    // Cloned so edits never touch a shared template object.
    createMutation.mutate({ name, graph: structuredClone(graph) });

  return { createDraft, isCreating: createMutation.isPending };
}
