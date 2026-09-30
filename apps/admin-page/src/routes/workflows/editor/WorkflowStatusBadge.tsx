import type { Workflow } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

import { Badge } from '@/components/ui/badge';

export type WorkflowState = 'active' | 'inactive' | 'draft';

/** Active; Inactive = turned on before, now off; Draft = never turned on. */
export function workflowState(workflow: Pick<Workflow, 'status' | 'everActivated'>): WorkflowState {
  if (workflow.status === 'active') return 'active';
  return workflow.everActivated ? 'inactive' : 'draft';
}

const badges = {
  active: { variant: 'success-soft', label: __('Active', 'yay-automation') },
  inactive: { variant: 'secondary', label: __('Inactive', 'yay-automation') },
  draft: { variant: 'primary-soft', label: __('Draft', 'yay-automation') },
} as const;

export function WorkflowStatusBadge({ state }: { state: WorkflowState }) {
  const { variant, label } = badges[state];
  return <Badge variant={variant}>{label}</Badge>;
}
