import type { Workflow, WorkflowDraft, WorkflowIssue } from '@yay-automation/types';
import { HTTPError, type ResponsePromise } from 'ky';
import { __ } from '@wordpress/i18n';

import { api } from '@/lib/api/api';
import { getErrorJson, getErrorMsg } from '@/lib/helpers/response.helper';

/** REST client for `yay-automation/v1/workflows`. Errors are rethrown as `ApiError` with the server's message. */

export class ApiError extends Error {
  constructor(
    message: string,
    /** HTTP status; undefined for network errors and timeouts. */
    readonly status?: number,
    /** What the server's workflow validation rejected (400 `data.errors`). */
    readonly issues: WorkflowIssue[] = [],
  ) {
    super(message);
  }
}

async function request<T>(response: ResponsePromise): Promise<T> {
  try {
    return await response.json<T>();
  } catch (error) {
    let message: string | undefined;
    let issues: WorkflowIssue[] = [];
    try {
      message = await getErrorMsg(error);
      const errors = (await getErrorJson(error))?.data?.errors;
      if (Array.isArray(errors)) issues = errors;
    } catch {
      // Error body isn't JSON (PHP fatal, proxy error page): fall back to the generic message.
    }
    throw new ApiError(
      message || __('Something went wrong. Please try again.', 'yay-automation'),
      error instanceof HTTPError ? error.response.status : undefined,
      issues,
    );
  }
}

/** The editable fields of a workflow. Used for the form's values and as the request body. */
export const toDraft = ({ id, name, status, graph }: WorkflowDraft): WorkflowDraft => ({ id, name, status, graph });

export const listWorkflows = () => request<Workflow[]>(api.get('workflows'));

/**
 * Workflow ids are post ids. A non-numeric id can't exist and wouldn't match the REST route, so
 * answer "not found" here instead of showing WordPress's generic "no route" error.
 */
export const getWorkflow = (id: string) =>
  /^\d+$/.test(id)
    ? request<Workflow>(api.get(`workflows/${id}`))
    : Promise.reject(new ApiError(__('Workflow not found.', 'yay-automation'), 404));

/** Creates when there's no `id` (a new draft), updates otherwise. */
export const saveWorkflow = (draft: WorkflowDraft) =>
  request<Workflow>(
    draft.id
      ? api.put(`workflows/${draft.id}`, { json: toDraft(draft) })
      : api.post('workflows', { json: toDraft(draft) }),
  );

export const deleteWorkflow = (id: string) => request<{ deleted: boolean }>(api.delete(`workflows/${id}`));
