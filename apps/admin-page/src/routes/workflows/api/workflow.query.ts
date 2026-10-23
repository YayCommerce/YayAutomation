import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { RunStatus, Workflow } from '@yaymatik/types';

import {
  activateWorkflow,
  ApiError,
  applyDraft,
  createWorkflow,
  deactivateWorkflow,
  deleteWorkflow,
  discardDraft,
  getRun,
  getWorkflow,
  listRuns,
  listVersions,
  listWorkflows,
  updateWorkflow,
} from './workflow.api';

export const workflowKeys = {
  all: ['workflows'] as const,
  list: () => [...workflowKeys.all, 'list'] as const,
  detail: (id: string) => [...workflowKeys.all, 'detail', id] as const,
  versions: (id: string) => [...workflowKeys.all, 'versions', id] as const,
  versionsPage: (id: string, page: number) => [...workflowKeys.versions(id), page] as const,
  runs: (id: string) => [...workflowKeys.all, 'runs', id] as const,
  runsPage: (id: string, page: number, statuses: RunStatus[]) => [...workflowKeys.runs(id), statuses, page] as const,
  run: (workflowId: string, runId: string) => [...workflowKeys.runs(workflowId), 'detail', runId] as const,
};

// Something missing stays missing, so don't delay the message; retry anything else (blips, timeouts).
const retryUnlessNotFound = (failureCount: number, error: Error) =>
  !(error instanceof ApiError && error.status === 404) && failureCount < 2;

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
    retry: retryUnlessNotFound,
  });
}

/** One page of version history. Keeps showing the previous page while the next one loads. */
export function useWorkflowVersionsQuery(id: string, page: number) {
  return useQuery({
    queryKey: workflowKeys.versionsPage(id, page),
    queryFn: () => listVersions(id, page),
    placeholderData: keepPreviousData,
  });
}

/**
 * One page of a workflow's runs. Keeps showing the previous page while the next one loads, but not
 * the previous filter's rows under a new filter.
 */
export function useWorkflowRunsQuery(id: string, page: number, statuses: RunStatus[]) {
  const filterKey = workflowKeys.runsPage(id, page, statuses).slice(0, -1);
  return useQuery({
    queryKey: workflowKeys.runsPage(id, page, statuses),
    queryFn: () => listRuns(id, page, statuses),
    placeholderData: (previous, previousQuery) =>
      previousQuery && JSON.stringify(previousQuery.queryKey.slice(0, -1)) === JSON.stringify(filterKey)
        ? previous
        : undefined,
  });
}

/** One run of the workflow, with its steps; none while `runId` is null. Under the workflow's runs key, so they refresh together. */
export function useRunQuery(workflowId: string, runId: string | null) {
  return useQuery({
    queryKey: workflowKeys.run(workflowId, runId ?? ''),
    queryFn: () => getRun(runId ?? ''),
    enabled: runId !== null,
    retry: retryUnlessNotFound,
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
      // Every save can add or change a version.
      void queryClient.invalidateQueries({ queryKey: workflowKeys.versions(saved.id) });
      onSaved?.(saved);
    },
    onError: (error) => onFailed?.(error),
  });
}

type CreateVariables = Partial<Pick<Workflow, 'name' | 'graph'>> & { id?: never };

export const useCreateWorkflowMutation = (callbacks?: MutationCallbacks) =>
  useWorkflowMutation<CreateVariables>(createWorkflow, callbacks);

export const useUpdateWorkflowMutation = (callbacks?: MutationCallbacks) =>
  useWorkflowMutation(
    ({ id, ...changes }: { id: string } & Partial<Pick<Workflow, 'name' | 'graph'>>) => updateWorkflow(id, changes),
    callbacks,
  );

/**
 * Autosave's graph save. No ky retries: a failure shows at once (the status pill has Retry, and the
 * next edit saves again), instead of after several seconds of silent retries, e.g. when leaving.
 */
export const useAutosaveMutation = () =>
  useWorkflowMutation(({ id, graph }: Pick<Workflow, 'id' | 'graph'>) => updateWorkflow(id, { graph }, { retry: 0 }));

const workflowActions = {
  activate: activateWorkflow,
  deactivate: deactivateWorkflow,
  apply: applyDraft,
  discard: discardDraft,
};

/** Activate, deactivate, update (`apply`) or discard the draft. Activate and update reject with `ApiError.issues`. */
export const useWorkflowActionMutation = (action: keyof typeof workflowActions, callbacks?: MutationCallbacks) =>
  useWorkflowMutation(({ id }: { id: string }) => workflowActions[action](id), callbacks);

export function useDeleteWorkflowMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => deleteWorkflow(id),
    onSuccess: (_, id) => {
      queryClient.removeQueries({ queryKey: workflowKeys.detail(id) });
      queryClient.removeQueries({ queryKey: workflowKeys.versions(id) });
      queryClient.setQueryData<Workflow[]>(workflowKeys.list(), (list) => list?.filter((w) => w.id !== id));
    },
  });
}
