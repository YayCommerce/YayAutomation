import type { Workflow } from '@yay-automation/types';
import { Ellipsis, Pencil, Power, PowerOff, SquarePen, Trash2 } from 'lucide-react';
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
import { toast } from '@/components/ui/sonner';
import { ApiError } from '../../api/workflow.api';
import { useWorkflowActionMutation } from '../../api/workflow.query';
import { activateIssues } from '../../model/validation';
import {
  DeleteWorkflowDialog,
  RenameWorkflowDialog,
  TurnOffDialog,
  TurnOnDialog,
} from '../../shared/actions/WorkflowActions';
import type { EditorNavState } from '../../shared/nav/editor.nav';

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
            __('Actions for %s', 'yay-automation'),
            workflow.name,
          )}
        >
          <Ellipsis />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="min-w-44">
        <DropdownMenuItem onSelect={() => navigate(`/workflows/${workflow.id}/edit`)}>
          <SquarePen />
          {__('Edit', 'yay-automation')}
        </DropdownMenuItem>
        <DropdownMenuItem onSelect={() => onAction('rename')}>
          <Pencil />
          {__('Rename', 'yay-automation')}
        </DropdownMenuItem>
        {isActive ? (
          <DropdownMenuItem onSelect={() => onAction('deactivate')}>
            <PowerOff />
            {__('Deactivate', 'yay-automation')}
          </DropdownMenuItem>
        ) : (
          // Not offered while what Activate would make live has issues: the server would refuse.
          activateIssues(workflow).length === 0 && (
            <DropdownMenuItem onSelect={() => onAction('activate')}>
              <Power />
              {__('Activate', 'yay-automation')}
            </DropdownMenuItem>
          )
        )}
        <DropdownMenuSeparator />
        <DropdownMenuItem variant="destructive" onSelect={() => onAction('delete')}>
          <Trash2 />
          {__('Delete', 'yay-automation')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/**
 * The dialogs behind the row menus, mounted once by the table. An Activate refused for issues
 * offers the editor, which opens showing them.
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
  const navigate = useNavigate();
  const activate = useWorkflowActionMutation('activate');
  const deactivate = useWorkflowActionMutation('deactivate');
  const workflow = target?.workflow;
  const action = open ? target?.action : undefined;

  const runActivate = (id: string) =>
    activate.mutate(
      { id },
      {
        onSuccess: () => toast.success(__('Workflow activated', 'yay-automation')),
        onError: (error) =>
          error instanceof ApiError && error.issues.length
            ? toast.error(error.message, {
                action: {
                  label: __('Fix in editor', 'yay-automation'),
                  onClick: () =>
                    navigate(`/workflows/${id}/edit`, { state: { showIssues: true } satisfies EditorNavState }),
                },
              })
            : toast.error(error.message),
        onSettled: onClose,
      },
    );

  const runDeactivate = (id: string) =>
    deactivate.mutate(
      { id },
      {
        onSuccess: () => toast.success(__('Workflow deactivated', 'yay-automation')),
        onError: (error) => toast.error(error.message),
        onSettled: onClose,
      },
    );

  const closeOnDismiss = (open: boolean) => !open && onClose();

  return (
    <>
      {/* Mounted per opening, so it starts from the current name. */}
      {action === 'rename' && workflow && <RenameWorkflowDialog workflow={workflow} onClose={onClose} />}
      <TurnOnDialog
        open={action === 'activate'}
        onOpenChange={closeOnDismiss}
        onConfirm={() => workflow && runActivate(workflow.id)}
        pending={activate.isPending}
        hasDraft={!!workflow?.draft}
      />
      <TurnOffDialog
        open={action === 'deactivate'}
        onOpenChange={closeOnDismiss}
        onConfirm={() => workflow && runDeactivate(workflow.id)}
        pending={deactivate.isPending}
      />
      <DeleteWorkflowDialog workflow={action === 'delete' ? (workflow ?? null) : null} onClose={onClose} />
    </>
  );
}
