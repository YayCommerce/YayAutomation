import { createContext, useContext } from 'react';
import { useForm } from '@tanstack/react-form';
import type { Workflow } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

import { toast } from '@/components/ui/sonner';
import { ApiError, toDraft } from './workflow.api';
import { useUpdateWorkflowMutation, useWorkflowActionMutation } from './workflow.query';

/**
 * The editable draft. Query holds what's saved; the form holds what's being changed,
 * and gives us dirty tracking + submit for free.
 */
export function useWorkflowForm(workflow: Workflow) {
  const updateMutation = useUpdateWorkflowMutation();
  const activateMutation = useWorkflowActionMutation('activate');
  const deactivateMutation = useWorkflowActionMutation('deactivate');

  return useForm({
    defaultValues: toDraft(workflow),
    onSubmit: async ({ value, formApi }) => {
      try {
        const { id } = workflow;
        let saved = await updateMutation.mutateAsync({ id, name: value.name, graph: value.graph });
        // Status only changes through its own endpoints; turning on is rejected while there are issues.
        if (value.status !== saved.status) {
          saved = await (value.status === 'active' ? activateMutation : deactivateMutation).mutateAsync({ id });
        }
        // Saved values become the new defaults, so the form is clean again. Skipped if the user
        // kept editing during the request (values changed identity), so those edits stay dirty.
        if (formApi.state.values === value) {
          formApi.reset(toDraft(saved));
        }
        toast.success(__('Workflow saved', 'yay-automation'));
      } catch (error) {
        // Server-side validation lists what it rejected; show that, since the editor's own checks passed.
        const issues = error instanceof ApiError ? error.issues : [];
        toast.error(error instanceof Error ? error.message : __('Could not save workflow', 'yay-automation'), {
          description: issues.length ? issues.map((issue) => issue.message).join(' ') : undefined,
        });
      }
    },
  });
}

export type WorkflowForm = ReturnType<typeof useWorkflowForm>;

/** Lets ReactFlow custom nodes (rendered deep inside <ReactFlow>) reach the form. */
export const WorkflowFormContext = createContext<WorkflowForm | null>(null);

export function useWorkflowFormContext() {
  const form = useContext(WorkflowFormContext);
  if (!form) throw new Error('useWorkflowFormContext must be used inside WorkflowFormContext');
  return form;
}
