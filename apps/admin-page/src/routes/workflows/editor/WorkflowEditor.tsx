import type { Workflow } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EditorLayout } from './EditorLayout';
import { useWorkflowForm, WorkflowFormContext } from './useWorkflowForm';
import { WorkflowCanvas } from './WorkflowCanvas';

/** Owns the form for one loaded workflow. Mount with `key={workflow.id}` so switching workflows starts fresh. */
export function WorkflowEditor({ workflow }: { workflow: Workflow }) {
  const form = useWorkflowForm(workflow);

  return (
    <WorkflowFormContext.Provider value={form}>
      <EditorLayout
        toolbar={
          <>
            <span className="font-medium">{workflow.name}</span>
            <Badge variant="secondary">{workflow.status}</Badge>
            {/* Subscribe re-renders only this button when dirty/submitting flip. */}
            <form.Subscribe selector={(state) => [state.isDirty, state.isSubmitting] as const}>
              {([isDirty, isSubmitting]) => (
                <Button
                  className="ml-auto"
                  size="sm"
                  disabled={!isDirty || isSubmitting}
                  onClick={() => form.handleSubmit()}
                >
                  {isSubmitting ? __('Saving…', 'yay-automation') : __('Save', 'yay-automation')}
                </Button>
              )}
            </form.Subscribe>
          </>
        }
      >
        <WorkflowCanvas />
      </EditorLayout>
    </WorkflowFormContext.Provider>
  );
}
