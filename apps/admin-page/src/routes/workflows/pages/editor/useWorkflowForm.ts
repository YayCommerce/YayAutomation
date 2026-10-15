import { createContext, useContext } from 'react';
import { useForm } from '@tanstack/react-form';
import type { Workflow } from '@yaymatik/types';

/**
 * The graph being edited: the workflow's draft, or its live / saved graph when there's none. The
 * query holds what's saved; autosave sends the form's graph.
 * Name and status aren't edited here: they change through their own requests.
 */
export function useWorkflowForm(workflow: Workflow) {
  return useForm({ defaultValues: { graph: workflow.draft ?? workflow.graph } });
}

export type WorkflowForm = ReturnType<typeof useWorkflowForm>;

/** Lets ReactFlow custom nodes (rendered deep inside <ReactFlow>) reach the form. */
export const WorkflowFormContext = createContext<WorkflowForm | null>(null);

export function useWorkflowFormContext() {
  const form = useContext(WorkflowFormContext);
  if (!form) throw new Error('useWorkflowFormContext must be used inside WorkflowFormContext');
  return form;
}
