import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Workflow } from '@yay-automation/types';

import { ApiError, deleteWorkflow, getWorkflow, listWorkflows, saveWorkflow } from './workflow.api';

export const workflowKeys = {
  all: ['workflows'] as const,
  list: () => [...workflowKeys.all, 'list'] as const,
  detail: (id: string) => [...workflowKeys.all, 'detail', id] as const,
};

export function useWorkflowsQuery() {
  return useQuery({
    queryKey: workflowKeys.list(),
    queryFn: listWorkflows,
  });
}

/** Server state: the last saved workflow. The editable draft lives in the form, not here. */
export function useWorkflowQuery(id: string) {
  const queryClient = useQueryClient();

  return useQuery({
    queryKey: workflowKeys.detail(id),
    queryFn: () => getWorkflow(id),
    // Opened from the list: the row already holds this workflow, so render it right away.
    initialData: () => queryClient.getQueryData<Workflow[]>(workflowKeys.list())?.find((w) => w.id === id),
    initialDataUpdatedAt: () => queryClient.getQueryState(workflowKeys.list())?.dataUpdatedAt,
    // A missing workflow stays missing, so don't delay the message; retry anything else (blips, timeouts).
    retry: (failureCount, error) => !(error instanceof ApiError && error.status === 404) && failureCount < 2,
  });
}

/**
 * Creates (no `id` yet) or updates. `onSaved` / `onFailed` run even if the calling component has
 * unmounted, unlike callbacks passed to `mutate()`.
 */
export function useSaveWorkflowMutation({
  onSaved,
  onFailed,
}: { onSaved?: (saved: Workflow) => void; onFailed?: (error: Error) => void } = {}) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: saveWorkflow,
    // Stop an in-flight refetch from landing after the save and overwriting it with older data.
    onMutate: (draft) =>
      draft.id ? queryClient.cancelQueries({ queryKey: workflowKeys.detail(draft.id) }) : undefined,
    onSuccess: (saved) => {
      // Seeds the editor (right after a draft is created, and when re-opened) without a refetch.
      queryClient.setQueryData(workflowKeys.detail(saved.id), saved);
      // Not awaited: callers (e.g. opening a new draft) shouldn't wait for the list to refetch.
      void queryClient.invalidateQueries({ queryKey: workflowKeys.list() });
      onSaved?.(saved);
    },
    onError: (error) => onFailed?.(error),
  });
}

export function useDeleteWorkflowMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => deleteWorkflow(id),
    onSuccess: (_, id) => {
      queryClient.removeQueries({ queryKey: workflowKeys.detail(id) });
      queryClient.setQueryData<Workflow[]>(workflowKeys.list(), (list) => list?.filter((w) => w.id !== id));
    },
  });
}
