import type { Workflow } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';

export type WorkflowState = 'active' | 'inactive' | 'draft';

/** Active; Inactive = activated before, now off; Draft = never activated. */
export function workflowState(workflow: Pick<Workflow, 'status' | 'everActivated'>): WorkflowState {
  if (workflow.status === 'active') return 'active';
  return workflow.everActivated ? 'inactive' : 'draft';
}

const badges = {
  active: { tone: 'bg-success/15 text-success-soft-foreground', label: __('Active', 'yay-automation') },
  inactive: { tone: 'bg-muted text-muted-foreground-600', label: __('Inactive', 'yay-automation') },
  draft: { tone: 'bg-primary/14 text-link', label: __('Draft', 'yay-automation') },
} as const;

/** The app's badge shape (6px corners), for the status and the badges beside it. */
export const appBadge = 'rounded-md border-transparent';

export function WorkflowStatusBadge({ state }: { state: WorkflowState }) {
  const { tone, label } = badges[state];
  return (
    <Badge variant="secondary" className={cn(appBadge, tone)}>
      {label}
    </Badge>
  );
}
