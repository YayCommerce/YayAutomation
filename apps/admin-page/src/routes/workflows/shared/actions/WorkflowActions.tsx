import { useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import type { Workflow } from '@yaymatik/types';
import { ChevronDown, Pencil, Trash2 } from 'lucide-react';
import { useNavigate } from 'react-router';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button, LoadingButton } from '@/components/ui/button';
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { toast } from '@/components/ui/sonner';
import { FieldDialog } from '@/components/widget/FieldDialog';
import { ApiError } from '../../api/workflow.api';
import { useDeleteWorkflowMutation, useUpdateWorkflowMutation, workflowKeys } from '../../api/workflow.query';
import { messages } from '../../model/messages';

/**
 * "More actions" menu: Rename and Delete. Delete returns to the list, after `onDeleted` (e.g. the
 * editor stopping autosave). Shared by the Overview and editor.
 */
export function MoreActionsMenu({ workflow, onDeleted }: { workflow: Workflow; onDeleted?: () => void }) {
  const navigate = useNavigate();
  const [dialog, setDialog] = useState<'rename' | 'delete' | null>(null);

  return (
    <>
      {/* Non-modal: a modal menu that opens a dialog on select can leave the page unclickable. */}
      <DropdownMenu modal={false}>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" size="sm">
            {__('More actions', 'yaymatik-automation-for-woocommerce')}
            <ChevronDown />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onSelect={() => setDialog('rename')}>
            <Pencil />
            {__('Rename', 'yaymatik-automation-for-woocommerce')}
          </DropdownMenuItem>
          <DropdownMenuItem variant="destructive" onSelect={() => setDialog('delete')}>
            <Trash2 />
            {__('Delete', 'yaymatik-automation-for-woocommerce')}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      {/* Mounted per opening, so it starts from the current name. */}
      {dialog === 'rename' && <RenameWorkflowDialog workflow={workflow} onClose={() => setDialog(null)} />}
      <DeleteWorkflowDialog
        workflow={dialog === 'delete' ? workflow : null}
        onClose={() => setDialog(null)}
        onDeleted={() => {
          onDeleted?.();
          navigate('/workflows');
        }}
      />
    </>
  );
}

/** Mount it to open it; it starts from the current name and calls `onClose` when done. */
export function RenameWorkflowDialog({ workflow, onClose }: { workflow: Workflow; onClose: () => void }) {
  const updateMutation = useUpdateWorkflowMutation();

  const save = (name: string) =>
    updateMutation.mutate(
      { id: workflow.id, name },
      {
        onSuccess: () => {
          toast.success(__('Workflow renamed', 'yaymatik-automation-for-woocommerce'));
          onClose();
        },
        onError: (error) => toast.error(error.message),
      },
    );

  return (
    <FieldDialog
      title={__('Rename workflow', 'yaymatik-automation-for-woocommerce')}
      description={__('Enter a new name for this workflow.', 'yaymatik-automation-for-woocommerce')}
      label={__('Name', 'yaymatik-automation-for-woocommerce')}
      initialValue={workflow.name}
      pending={updateMutation.isPending}
      onSave={save}
      onClose={onClose}
    />
  );
}

/**
 * Confirms and deletes `workflow`; closed while it's null. Stays open while deleting, so a failure can
 * be retried. An active workflow can't be deleted: it shows why instead (the server refuses too).
 */
export function DeleteWorkflowDialog({
  workflow,
  onClose,
  onDeleted,
}: {
  workflow: Workflow | null;
  onClose: () => void;
  onDeleted?: () => void;
}) {
  const deleteMutation = useDeleteWorkflowMutation();
  const queryClient = useQueryClient();

  // What the dialog shows is fixed per opening: `workflow` turns null as it closes, and the content
  // mustn't flip to the other view during the close animation.
  const [shown, setShown] = useState<{ workflow: Workflow; refusedMessage: string | null } | null>(null);
  if (workflow && workflow !== shown?.workflow) {
    setShown({ workflow, refusedMessage: null });
  }
  // `refusedMessage`: the server said it's active, though this copy said not (activated in another tab).
  const blocked = shown?.workflow.status === 'active' || !!shown?.refusedMessage;

  const confirm = () => {
    if (!workflow) return;
    deleteMutation.mutate(workflow.id, {
      onSuccess: () => {
        toast.success(__('Workflow deleted', 'yaymatik-automation-for-woocommerce'));
        onClose();
        onDeleted?.();
      },
      onError: (error) => {
        if (error instanceof ApiError && error.code === 'yaymatik_delete_active') {
          setShown({ workflow, refusedMessage: error.message });
          // This copy was stale; refetch so the page shows it's active (and offers Deactivate).
          void queryClient.invalidateQueries({ queryKey: workflowKeys.all });
        } else {
          toast.error(error.message);
        }
      },
    });
  };

  return (
    <AlertDialog open={!!workflow} onOpenChange={(open) => !open && !deleteMutation.isPending && onClose()}>
      <AlertDialogContent>
        {blocked ? (
          // The server's wording when it refused; the shared text otherwise (no request was made).
          <DeleteBlockedContent message={shown?.refusedMessage ?? undefined} />
        ) : (
          <>
            <AlertDialogHeader>
              <AlertDialogTitle>
                {sprintf(
                  /* translators: %s: workflow name */
                  __('Delete “%s”?', 'yaymatik-automation-for-woocommerce'),
                  shown?.workflow.name ?? '',
                )}
              </AlertDialogTitle>
              <AlertDialogDescription>
                {__('This can’t be undone.', 'yaymatik-automation-for-woocommerce')}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel disabled={deleteMutation.isPending}>
                {__('Cancel', 'yaymatik-automation-for-woocommerce')}
              </AlertDialogCancel>
              {/* Not AlertDialogAction: that closes the dialog before the request finishes. */}
              <LoadingButton
                variant="destructive"
                loading={deleteMutation.isPending}
                disabled={deleteMutation.isPending}
                onClick={confirm}
              >
                {__('Delete', 'yaymatik-automation-for-woocommerce')}
              </LoadingButton>
            </AlertDialogFooter>
          </>
        )}
      </AlertDialogContent>
    </AlertDialog>
  );
}

export interface ConfirmProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
  /** Keeps the dialog open, buttons disabled, while the action runs. */
  pending: boolean;
}

/** Confirm with Cancel and one action; stays open while `pending`. */
export function ConfirmDialog({
  title,
  description,
  confirmLabel,
  cancelLabel = __('Cancel', 'yaymatik-automation-for-woocommerce'),
  destructive,
  open,
  onOpenChange,
  onConfirm,
  pending,
}: ConfirmProps & {
  title: string;
  description: ReactNode;
  confirmLabel: string;
  cancelLabel?: string;
  destructive?: boolean;
}) {
  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline" disabled={pending}>
              {cancelLabel}
            </Button>
          </DialogClose>
          <LoadingButton
            variant={destructive ? 'destructive' : 'primary'}
            loading={pending}
            disabled={pending}
            onClick={onConfirm}
          >
            {confirmLabel}
          </LoadingButton>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** `hasDraft`: Activate makes the saved content live, so say the draft changes aren't included. */
export function ActivateDialog({ name, hasDraft, ...props }: ConfirmProps & { name: string; hasDraft?: boolean }) {
  return (
    <ConfirmDialog
      {...props}
      title={sprintf(
        /* translators: %s: workflow name */
        __('Activate “%s”?', 'yaymatik-automation-for-woocommerce'),
        name,
      )}
      description={
        <>
          {__(
            'It starts running right away. You can deactivate it at any time.',
            'yaymatik-automation-for-woocommerce',
          )}
          {hasDraft && (
            <span className="mt-2 block">
              {__(
                'Your draft changes aren’t included. Update first to include them.',
                'yaymatik-automation-for-woocommerce',
              )}
            </span>
          )}
        </>
      }
      confirmLabel={__('Activate', 'yaymatik-automation-for-woocommerce')}
    />
  );
}

/** For a success toast: "2 waiting runs were cancelled."; undefined for none. */
export const cancelledRunsText = (count = 0) =>
  count > 0
    ? sprintf(
        /* translators: %d: number of runs */
        _n(
          '%d waiting run was cancelled.',
          '%d waiting runs were cancelled.',
          count,
          'yaymatik-automation-for-woocommerce',
        ),
        count,
      )
    : undefined;

/**
 * The waiting runs a confirm dialog warns about. Held while its action runs and while it closes: the
 * saved workflow lands in the cache first, with none waiting.
 */
function useHeldCount(count: number, hold: boolean) {
  const [held, setHeld] = useState(count);
  if (!hold && held !== count) setHeld(count);
  return hold ? held : count;
}

export function UpdateDialog({ waitingRuns, ...props }: ConfirmProps & { waitingRuns: number }) {
  const count = useHeldCount(waitingRuns, props.pending || !props.open);
  return (
    <ConfirmDialog
      {...props}
      title={__('Update this workflow?', 'yaymatik-automation-for-woocommerce')}
      description={
        count > 0
          ? sprintf(
              /* translators: %d: number of runs */
              _n(
                'It’s active, so your draft changes take effect right away. %d waiting run will be cancelled. Runs under way finish on the version they started on.',
                'It’s active, so your draft changes take effect right away. %d waiting runs will be cancelled. Runs under way finish on the version they started on.',
                count,
                'yaymatik-automation-for-woocommerce',
              ),
              count,
            )
          : __(
              'It’s active, so your draft changes take effect right away. Runs under way finish on the version they started on.',
              'yaymatik-automation-for-woocommerce',
            )
      }
      confirmLabel={__('Update', 'yaymatik-automation-for-woocommerce')}
    />
  );
}

export function DiscardChangesDialog(props: ConfirmProps) {
  return (
    <ConfirmDialog
      {...props}
      title={__('Discard draft changes?', 'yaymatik-automation-for-woocommerce')}
      description={__(
        'Your draft changes will be deleted. The workflow stays as it was.',
        'yaymatik-automation-for-woocommerce',
      )}
      confirmLabel={__('Discard changes', 'yaymatik-automation-for-woocommerce')}
      destructive
    />
  );
}

/** "New events won't start it. …", then how many waiting runs it cancels (across all of them for `several`). */
function deactivateText(several: boolean, count: number) {
  if (count === 0) {
    return several
      ? __(
          'New events won’t start them. Runs under way stop before their next step. You can activate them again at any time.',
          'yaymatik-automation-for-woocommerce',
        )
      : __(
          'New events won’t start it. Runs under way stop before their next step. You can activate it again at any time.',
          'yaymatik-automation-for-woocommerce',
        );
  }
  return sprintf(
    several
      ? /* translators: %d: number of runs */
        _n(
          'New events won’t start them. Runs under way stop before their next step. You can activate them again at any time. %d waiting run will be cancelled.',
          'New events won’t start them. Runs under way stop before their next step. You can activate them again at any time. %d waiting runs will be cancelled.',
          count,
          'yaymatik-automation-for-woocommerce',
        )
      : /* translators: %d: number of runs */
        _n(
          'New events won’t start it. Runs under way stop before their next step. You can activate it again at any time. %d waiting run will be cancelled.',
          'New events won’t start it. Runs under way stop before their next step. You can activate it again at any time. %d waiting runs will be cancelled.',
          count,
          'yaymatik-automation-for-woocommerce',
        ),
    count,
  );
}

/** One workflow (`name`), or `count` of them from the list's bulk actions; `waitingRuns` across all of them. */
export function DeactivateDialog(
  props: ConfirmProps & { waitingRuns: number } & ({ name: string; count?: never } | { count: number; name?: never }),
) {
  const { name, count, waitingRuns, ...confirm } = props;
  const several = count !== undefined && count > 1;
  const waiting = useHeldCount(waitingRuns, confirm.pending || !confirm.open);
  return (
    <ConfirmDialog
      {...confirm}
      title={
        count === undefined
          ? sprintf(
              /* translators: %s: workflow name */
              __('Deactivate “%s”?', 'yaymatik-automation-for-woocommerce'),
              name,
            )
          : sprintf(
              /* translators: %d: number of workflows */
              _n('Deactivate %d workflow?', 'Deactivate %d workflows?', count, 'yaymatik-automation-for-woocommerce'),
              count,
            )
      }
      description={deactivateText(several, waiting)}
      confirmLabel={__('Deactivate', 'yaymatik-automation-for-woocommerce')}
    />
  );
}

/**
 * "Couldn't delete: still active" body with a single Back button, for inside an AlertDialog.
 * `several`: the list's bulk delete. `message`: the server's own wording, when it refused.
 */
export function DeleteBlockedContent({ several = false, message }: { several?: boolean; message?: string }) {
  return (
    <>
      <AlertDialogHeader>
        <AlertDialogTitle>
          {several
            ? __('There was a problem deleting some workflows', 'yaymatik-automation-for-woocommerce')
            : __('There was a problem deleting this workflow', 'yaymatik-automation-for-woocommerce')}
        </AlertDialogTitle>
        <AlertDialogDescription>
          {message ??
            (several
              ? __('Deactivate these workflows before you delete them.', 'yaymatik-automation-for-woocommerce')
              : messages.deleteActive)}
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>{__('Back', 'yaymatik-automation-for-woocommerce')}</AlertDialogCancel>
      </AlertDialogFooter>
    </>
  );
}

/** `count`: steps selected on the canvas (Backspace), one by default. */
export function DeleteStepDialog({ count = 1, ...props }: ConfirmProps & { count?: number }) {
  return (
    <ConfirmDialog
      {...props}
      title={
        count > 1
          ? sprintf(
              /* translators: %d: number of steps */
              _n('Delete %d step?', 'Delete %d steps?', count, 'yaymatik-automation-for-woocommerce'),
              count,
            )
          : __('Delete this step?', 'yaymatik-automation-for-woocommerce')
      }
      description={__('This can’t be undone.', 'yaymatik-automation-for-woocommerce')}
      confirmLabel={__('Delete', 'yaymatik-automation-for-woocommerce')}
      destructive
    />
  );
}
