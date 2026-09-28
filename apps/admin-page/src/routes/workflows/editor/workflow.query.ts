import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { createWorkflow, getWorkflow, saveWorkflow } from './workflow.mock';

export const workflowKeys = {
  detail: (id: string) => ['workflow', id] as const,
};

/** Server state: the last saved workflow. The editable draft lives in the form, not here. */
export function useWorkflowQuery(id: string) {
  return useQuery({
    queryKey: workflowKeys.detail(id),
    queryFn: () => getWorkflow(id),
  });
}

export function useSaveWorkflowMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: saveWorkflow,
    // Stop an in-flight refetch from landing after the save and overwriting it with older data.
    onMutate: (workflow) => queryClient.cancelQueries({ queryKey: workflowKeys.detail(workflow.id) }),
    // Write the response into the cache so re-opening the editor shows the saved version immediately.
    onSuccess: (saved) => queryClient.setQueryData(workflowKeys.detail(saved.id), saved),
  });
}

/** Creates a draft (blank or from a template). Seeds the detail cache so the editor renders without a skeleton. */
export function useCreateWorkflowMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: createWorkflow,
    onSuccess: (created) => queryClient.setQueryData(workflowKeys.detail(created.id), created),
  });
}
