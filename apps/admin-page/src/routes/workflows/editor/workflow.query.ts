import { useQuery } from '@tanstack/react-query';

import { getWorkflow } from './workflow.mock';

export const workflowKeys = {
  detail: (id: string) => ['workflow', id] as const,
};

/** Server state: the last saved workflow. The editable draft lives in the form, not here. */
export function useWorkflowQuery(id: string) {
  return useQuery({
    queryKey: workflowKeys.detail(id),
    queryFn: () => getWorkflow(id),
  });
}
