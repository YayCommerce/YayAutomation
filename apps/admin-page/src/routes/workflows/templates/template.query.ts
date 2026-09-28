import { useQuery, useQueryClient } from '@tanstack/react-query';
import type { WorkflowTemplate } from '@yay-automation/types';

import { getTemplate, getTemplates } from './template.mock';

export const templateKeys = {
  all: ['workflow-templates'] as const,
  detail: (id: string) => ['workflow-templates', id] as const,
};

export function useTemplatesQuery() {
  return useQuery({
    queryKey: templateKeys.all,
    queryFn: getTemplates,
  });
}

export function useTemplateQuery(id: string) {
  const queryClient = useQueryClient();

  return useQuery({
    queryKey: templateKeys.detail(id),
    queryFn: () => getTemplate(id),
    // Opened from the library: the list already holds this template, so render it right away.
    initialData: () => queryClient.getQueryData<WorkflowTemplate[]>(templateKeys.all)?.find((t) => t.id === id),
    initialDataUpdatedAt: () => queryClient.getQueryState(templateKeys.all)?.dataUpdatedAt,
    // Unknown ids are a permanent "not found"; retrying only delays the message.
    retry: false,
  });
}
