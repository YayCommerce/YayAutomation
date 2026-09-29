import { createContext, useContext } from 'react';
import { useForm } from '@tanstack/react-form';
import type { WorkflowDraft } from '@yay-automation/types';
import { useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { toast } from '@/components/ui/sonner';
import { toDraft } from './workflow.api';
import { useSaveWorkflowMutation } from './workflow.query';

/**
 * The editable draft. Query holds what's saved; the form holds what's being changed,
 * and gives us dirty tracking + submit for free.
 */
export function useWorkflowForm(workflow: WorkflowDraft) {
  const saveMutation = useSaveWorkflowMutation();
  const navigate = useNavigate();

  return useForm({
    defaultValues: workflow,
    onSubmit: async ({ value, formApi }) => {
      try {
        const saved = await saveMutation.mutateAsync(value);
        // Saved values become the new defaults, so the form is clean again. Skipped if the user
        // kept editing during the request (values changed identity), so those edits stay dirty.
        if (formApi.state.values === value) {
          formApi.reset(toDraft(saved));
        } else if (!value.id) {
          // Edited during the first save: keep those edits, but record the id so the next Save
          // updates this workflow instead of creating a second one.
          formApi.setFieldValue('id', saved.id, { dontUpdateMeta: true });
        }
        toast.success(__('Workflow saved', 'yay-automation'));
        // Draft opened at /workflows/new: move to the workflow's own URL once nothing is unsaved.
        // The leave guard reads live form state, so this just-reset form isn't blocked. With edits
        // still pending we stay put; the next Save lands here again and redirects then.
        if (!workflow.id && !formApi.state.isDirty) {
          navigate(`/workflows/${saved.id}`, { replace: true });
        }
      } catch (error) {
        toast.error(error instanceof Error ? error.message : __('Could not save workflow', 'yay-automation'));
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
