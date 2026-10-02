import type { Workflow, WorkflowIssue, WorkflowVersionsPage } from '@yay-automation/types';
import { HTTPError, type Options, type ResponsePromise } from 'ky';
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
    /** WP_Error code, e.g. `yay_automation_no_draft`. */
    readonly code?: string,
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
    let code: string | undefined;
    try {
      message = await getErrorMsg(error);
      const json = await getErrorJson(error);
      if (Array.isArray(json?.data?.errors)) issues = json.data.errors;
      if (typeof json?.code === 'string') code = json.code;
    } catch {
      // Error body isn't JSON (PHP fatal, proxy error page): fall back to the generic message.
    }
    throw new ApiError(
      message || __('Something went wrong. Please try again.', 'yay-automation'),
      error instanceof HTTPError ? error.response.status : undefined,
      issues,
      code,
    );
  }
}

export const listWorkflows = () => request<Workflow[]>(api.get('workflows'));

/**
 * Workflow ids are post ids. A non-numeric id can't exist and wouldn't match the REST route, so
 * answer "not found" here instead of showing WordPress's generic "no route" error.
 */
export const getWorkflow = (id: string) =>
  /^\d+$/.test(id)
    ? request<Workflow>(api.get(`workflows/${id}`))
    : Promise.reject(new ApiError(__('Workflow not found.', 'yay-automation'), 404));

/** New inactive workflow. Issues are allowed. */
export const createWorkflow = ({ name, graph }: Pick<Workflow, 'name' | 'graph'>) =>
  request<Workflow>(api.post('workflows', { json: { name, graph } }));

/**
 * Saves the name and/or graph; issues are allowed. The server puts the graph into the live workflow
 * when inactive, or into the draft when active. `options` tunes the request (e.g. `retry`).
 */
export const updateWorkflow = (
  id: string,
  changes: Partial<Pick<Workflow, 'name' | 'graph'>>,
  options?: Pick<Options, 'retry'>,
) => request<Workflow>(api.put(`workflows/${id}`, { ...options, json: changes }));

/** 400 with `issues` when the workflow has any. */
export const activateWorkflow = (id: string) => request<Workflow>(api.post(`workflows/${id}/activate`));

/** Always allowed. A draft, if any, becomes the workflow's content. */
export const deactivateWorkflow = (id: string) => request<Workflow>(api.post(`workflows/${id}/deactivate`));

/** Draft → live. 400 with `issues` when the draft has any; 409 without a draft. */
export const applyDraft = (id: string) => request<Workflow>(api.post(`workflows/${id}/apply`));

export const discardDraft = (id: string) => request<Workflow>(api.delete(`workflows/${id}/draft`));

export const deleteWorkflow = (id: string) => request<{ deleted: boolean }>(api.delete(`workflows/${id}`));

/** Version history, newest first, 10 per page. `page` is 1-based. */
export const listVersions = (id: string, page: number) =>
  request<WorkflowVersionsPage>(api.get(`workflows/${id}/versions`, { searchParams: { page } }));
