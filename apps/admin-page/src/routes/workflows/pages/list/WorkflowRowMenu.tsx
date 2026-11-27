import type { Workflow } from '@yaymatik/types';
import { Ellipsis, Pencil, Power, Trash2, Type } from 'lucide-react';
import { useNavigate } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { canActivate } from '../../model/validation';
import { useActivateWorkflow } from '../../shared/actions/useActivateWorkflow';
import {
  ActivateDialog,
  DeactivateDialog,
  DeleteWorkflowDialog,
  RenameWorkflowDialog,
} from '../../shared/actions/WorkflowActions';

export type RowAction = 'rename' | 'activate' | 'deactivate' | 'delete';

/**
 * What a row's menu asked for. The table keeps it, so its dialog outlives a list refresh, and keeps
 * it after closing too, so the dialog's text doesn't change while it fades out.
 */
export type RowTarget = { action: RowAction; workflow: Workflow };

/**
 * A row's "⋯" menu: the actions the Overview and editor already offer (Edit, Rename, Activate /
 * Deactivate, Delete). Edit navigates; the others go to `onAction`, which opens their dialog.
 */
export function WorkflowRowMenu({ workflow, onAction }: { workflow: Workflow; onAction: (action: RowAction) => void }) {
  const navigate = useNavigate();
  const isActive = workflow.status === 'active';

  return (
    // Non-modal: a modal menu that opens a dialog on select can leave the page unclickable.
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={sprintf(
            /* translators: %s: workflow name */
            __('Actions for %s', 'yaymatik-automation-for-woocommerce'),
            workflow.name,
          )}
        >
          <Ellipsis />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="min-w-44">
        <DropdownMenuItem onSelect={() => navigate(`/workflows/${workflow.id}/edit`)}>
          <Pencil />
          {__('Edit', 'yaymatik-automation-for-woocommerce')}
        </DropdownMenuItem>
        <DropdownMenuItem onSelect={() => onAction('rename')}>
          <Type />
          {__('Rename', 'yaymatik-automation-for-woocommerce')}
        </DropdownMenuItem>
        {isActive ? (
          <DropdownMenuItem onSelect={() => onAction('deactivate')}>
            <Power />
            {__('Deactivate', 'yaymatik-automation-for-woocommerce')}
          </DropdownMenuItem>
        ) : (
          // Not offered while what Activate would make live has issues: the server would refuse.
          canActivate(workflow) && (
            <DropdownMenuItem onSelect={() => onAction('activate')}>
              <Power />
              {__('Activate', 'yaymatik-automation-for-woocommerce')}
            </DropdownMenuItem>
          )
        )}
        <DropdownMenuSeparator />
        <DropdownMenuItem variant="destructive" onSelect={() => onAction('delete')}>
          <Trash2 />
          {__('Delete', 'yaymatik-automation-for-woocommerce')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/**
 * The dialogs behind the row menus, mounted once by the table. Activate and Deactivate go through
 * `useActivateWorkflow`, like the Overview and editor.
 */
export function WorkflowRowDialogs({
  target,
  open,
  onClose,
}: {
  target: RowTarget | null;
  open: boolean;
  onClose: () => void;
}) {
  if (!target) return null;
  return (
    <RowDialogs
      key={target.workflow.id}
      workflow={target.workflow}
      action={open ? target.action : undefined}
      onClose={onClose}
    />
  );
}

function RowDialogs({
  workflow,
  action,
  onClose,
}: {
  workflow: Workflow;
  action: RowAction | undefined;
  onClose: () => void;
}) {
  const { activate, deactivate, pending } = useActivateWorkflow(workflow);
  const closeOnDismiss = (open: boolean) => !open && onClose();

  return (
    <>
      {/* Mounted per opening, so it starts from the current name. */}
      {action === 'rename' && <RenameWorkflowDialog workflow={workflow} onClose={onClose} />}
      <ActivateDialog
        open={action === 'activate'}
        onOpenChange={closeOnDismiss}
        onConfirm={() => void activate().finally(onClose)}
        pending={pending}
        name={workflow.name}
        hasDraft={!!workflow.draft}
      />
      <DeactivateDialog
        open={action === 'deactivate'}
        onOpenChange={closeOnDismiss}
        onConfirm={() => void deactivate().finally(onClose)}
        pending={pending}
        name={workflow.name}
        waitingRuns={workflow.waitingRuns}
      />
      <DeleteWorkflowDialog workflow={action === 'delete' ? workflow : null} onClose={onClose} />
    </>
  );
}
