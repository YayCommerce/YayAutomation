import type { Workflow, WorkflowIssue } from '@yay-automation/types';
import { useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { toast } from '@/components/ui/sonner';
import { ApiError, errorMessage } from '../../api/workflow.api';
import { useWorkflowActionMutation } from '../../api/workflow.query';
import type { EditorNavState } from '../nav/editor.nav';

export interface ActivateOptions {
  /** Runs first; false stops (e.g. the editor's save failed and it said so). */
  before?: () => Promise<boolean>;
  onSuccess?: () => void;
  /** The server refused for these issues. Default: a toast that offers the editor. */
  onIssues?: (issues: WorkflowIssue[], error: ApiError) => void;
}

/**
 * Activate and Deactivate for one workflow, shared by the editor, the Overview and the list: same
 * requests, toasts and refusal handling. Whether to offer Activate is `canActivate()` /
 * `activateIssues()` (model/validation), checked where the button is.
 */
export function useActivateWorkflow(workflow: Workflow) {
  const navigate = useNavigate();
  const activateMutation = useWorkflowActionMutation('activate');
  const deactivateMutation = useWorkflowActionMutation('deactivate');

  const offerEditor = (error: ApiError) =>
    toast.error(error.message, {
      action: {
        label: __('Fix in editor', 'yay-automation'),
        onClick: () =>
          navigate(`/workflows/${workflow.id}/edit`, { state: { showIssues: true } satisfies EditorNavState }),
      },
    });

  const activate = async ({ before, onSuccess, onIssues }: ActivateOptions = {}) => {
    if (before && !(await before())) return;
    try {
      await activateMutation.mutateAsync({ id: workflow.id });
      toast.success(__('Workflow activated', 'yay-automation'));
      onSuccess?.();
    } catch (error) {
      if (error instanceof ApiError && error.issues.length) {
        if (onIssues) onIssues(error.issues, error);
        else offerEditor(error);
      } else {
        toast.error(errorMessage(error));
      }
    }
  };

  const deactivate = async ({ before, onSuccess }: Omit<ActivateOptions, 'onIssues'> = {}) => {
    if (before && !(await before())) return;
    try {
      await deactivateMutation.mutateAsync({ id: workflow.id });
      toast.success(__('Workflow deactivated', 'yay-automation'));
      onSuccess?.();
    } catch (error) {
      toast.error(errorMessage(error));
    }
  };

  return {
    activate,
    deactivate,
    pending: activateMutation.isPending || deactivateMutation.isPending,
  };
}
