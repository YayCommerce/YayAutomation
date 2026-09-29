import type { WorkflowGraph } from '@yay-automation/types';
import { useNavigate } from 'react-router';

import { toast } from '@/components/ui/sonner';
import { useSaveWorkflowMutation } from './workflow.query';

/**
 * Creates an inactive draft right away and opens it, like Shopify Flow's Install. Used by "Use
 * template"; templates ship valid, since the server rejects any save with issues.
 */
export function useCreateDraft() {
  const navigate = useNavigate();
  // Hook-level callbacks, not `mutate(…, { onSuccess })`: those are skipped if the page unmounts
  // mid-request (e.g. Back on the preview), which would create a draft with no feedback.
  const saveMutation = useSaveWorkflowMutation({
    onSaved: (workflow) => navigate(`/workflows/${workflow.id}`),
    onFailed: (error) => toast.error(error.message),
  });

  const createDraft = (name: string, graph: WorkflowGraph) =>
    saveMutation.mutate(
      // Cloned so edits never touch a shared template object.
      { name, status: 'inactive', graph: structuredClone(graph) },
    );

  return { createDraft, isCreating: saveMutation.isPending };
}
