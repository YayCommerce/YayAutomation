import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Workflow, WorkflowDraft } from '@yay-automation/types';

import {
  activateWorkflow,
  ApiError,
  applyDraft,
  createWorkflow,
  deactivateWorkflow,
  deleteWorkflow,
  discardDraft,
  getWorkflow,
  listWorkflows,
  updateWorkflow,
} from './workflow.api';

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

interface MutationCallbacks {
  onSaved?: (saved: Workflow) => void;
  onFailed?: (error: Error) => void;
}

/**
 * Any request that returns the updated workflow. Seeds the detail cache with the result and refreshes
 * the list. `onSaved` / `onFailed` run even if the calling component has unmounted, unlike callbacks
 * passed to `mutate()`.
 */
function useWorkflowMutation<V extends { id?: string }>(
  mutationFn: (variables: V) => Promise<Workflow>,
  { onSaved, onFailed }: MutationCallbacks = {},
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    // Stop an in-flight refetch from landing after the save and overwriting it with older data.
    onMutate: ({ id }) => (id ? queryClient.cancelQueries({ queryKey: workflowKeys.detail(id) }) : undefined),
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

type CreateVariables = Pick<WorkflowDraft, 'name' | 'graph'> & { id?: never };

export const useCreateWorkflowMutation = (callbacks?: MutationCallbacks) =>
  useWorkflowMutation<CreateVariables>(createWorkflow, callbacks);

export const useUpdateWorkflowMutation = (callbacks?: MutationCallbacks) =>
  useWorkflowMutation(
    ({ id, ...changes }: { id: string } & Partial<Pick<WorkflowDraft, 'name' | 'graph'>>) =>
      updateWorkflow(id, changes),
    callbacks,
  );

const workflowActions = {
  activate: activateWorkflow,
  deactivate: deactivateWorkflow,
  apply: applyDraft,
  discard: discardDraft,
};

/** Turn on / off, apply or discard the draft. Activate and apply reject with `ApiError.issues`. */
export const useWorkflowActionMutation = (action: keyof typeof workflowActions, callbacks?: MutationCallbacks) =>
  useWorkflowMutation(({ id }: { id: string }) => workflowActions[action](id), callbacks);

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
