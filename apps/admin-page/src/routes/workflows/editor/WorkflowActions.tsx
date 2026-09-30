import { useState, type FormEvent } from 'react';
import type { Workflow } from '@yay-automation/types';
import { ChevronDown, Pencil, Trash2 } from 'lucide-react';
import { useNavigate } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ui/sonner';
import { ApiError } from './workflow.api';
import { useDeleteWorkflowMutation, useUpdateWorkflowMutation } from './workflow.query';

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
            {__('More actions', 'yay-automation')}
            <ChevronDown />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onSelect={() => setDialog('rename')}>
            <Pencil />
            {__('Rename', 'yay-automation')}
          </DropdownMenuItem>
          <DropdownMenuItem variant="destructive" onSelect={() => setDialog('delete')}>
            <Trash2 />
            {__('Delete', 'yay-automation')}
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
  const [name, setName] = useState(workflow.name);
  const updateMutation = useUpdateWorkflowMutation();

  const submit = (event: FormEvent) => {
    event.preventDefault();
    updateMutation.mutate(
      { id: workflow.id, name: name.trim() },
      {
        onSuccess: () => {
          toast.success(__('Workflow renamed', 'yay-automation'));
          onClose();
        },
        onError: (error) => toast.error(error.message),
      },
    );
  };

  return (
    <Dialog open onOpenChange={(open) => !open && !updateMutation.isPending && onClose()}>
      <DialogContent className="sm:max-w-md">
        <form onSubmit={submit} className="grid gap-4">
          <DialogHeader>
            <DialogTitle>{__('Rename workflow', 'yay-automation')}</DialogTitle>
            <DialogDescription className="sr-only">
              {__('Enter a new name for this workflow.', 'yay-automation')}
            </DialogDescription>
          </DialogHeader>
          <div className="grid gap-2">
            <Label htmlFor="workflow-name">{__('Name', 'yay-automation')}</Label>
            <Input id="workflow-name" value={name} onChange={(event) => setName(event.target.value)} autoFocus />
          </div>
          <DialogFooter>
            <DialogClose asChild>
              <Button variant="outline" disabled={updateMutation.isPending}>
                {__('Cancel', 'yay-automation')}
              </Button>
            </DialogClose>
            <LoadingButton
              type="submit"
              loading={updateMutation.isPending}
              disabled={!name.trim() || updateMutation.isPending}
            >
              {__('Save', 'yay-automation')}
            </LoadingButton>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
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
  // The server said it's on, though this copy of it was off (turned on in another tab).
  const [refused, setRefused] = useState(false);
  const blocked = workflow?.status === 'active' || refused;

  const close = () => {
    setRefused(false);
    onClose();
  };

  const confirm = () => {
    if (!workflow) return;
    deleteMutation.mutate(workflow.id, {
      onSuccess: () => {
        toast.success(__('Workflow deleted', 'yay-automation'));
        close();
        onDeleted?.();
      },
      onError: (error) => {
        if (error instanceof ApiError && error.code === 'yay_automation_delete_active') setRefused(true);
        else toast.error(error.message);
      },
    });
  };

  return (
    <AlertDialog open={!!workflow} onOpenChange={(open) => !open && !deleteMutation.isPending && close()}>
      <AlertDialogContent>
        {blocked ? (
          <>
            <AlertDialogHeader>
              <AlertDialogTitle>{__('There was a problem deleting this workflow', 'yay-automation')}</AlertDialogTitle>
              <AlertDialogDescription>
                {__(
                  'Because this workflow is still turned on, it couldn’t be deleted. Turn it off and try again.',
                  'yay-automation',
                )}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>{__('Back', 'yay-automation')}</AlertDialogCancel>
            </AlertDialogFooter>
          </>
        ) : (
          <>
            <AlertDialogHeader>
              <AlertDialogTitle>
                {sprintf(
                  /* translators: %s: workflow name */
                  __('Delete “%s”?', 'yay-automation'),
                  workflow?.name ?? '',
                )}
              </AlertDialogTitle>
              <AlertDialogDescription>{__('This can’t be undone.', 'yay-automation')}</AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel disabled={deleteMutation.isPending}>
                {__('Cancel', 'yay-automation')}
              </AlertDialogCancel>
              {/* Not AlertDialogAction: that closes the dialog before the request finishes. */}
              <LoadingButton
                variant="destructive"
                loading={deleteMutation.isPending}
                disabled={deleteMutation.isPending}
                onClick={confirm}
              >
                {__('Delete', 'yay-automation')}
              </LoadingButton>
            </AlertDialogFooter>
          </>
        )}
      </AlertDialogContent>
    </AlertDialog>
  );
}

interface ConfirmProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
  /** Keeps the dialog open, buttons disabled, while the action runs. */
  pending: boolean;
}

/** Confirm with Cancel and one action; stays open while `pending`. */
function ConfirmDialog({
  title,
  description,
  confirmLabel,
  cancelLabel = __('Cancel', 'yay-automation'),
  destructive,
  open,
  onOpenChange,
  onConfirm,
  pending,
}: ConfirmProps & {
  title: string;
  description: string;
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

export function TurnOnDialog(props: ConfirmProps) {
  return (
    <ConfirmDialog
      {...props}
      title={__('Ready to turn on your automation workflow?', 'yay-automation')}
      description={__('Turning on the workflow will activate the automation.', 'yay-automation')}
      confirmLabel={__('Turn on', 'yay-automation')}
    />
  );
}

export function ApplyChangesDialog(props: ConfirmProps) {
  return (
    <ConfirmDialog
      {...props}
      title={__('Apply changes?', 'yay-automation')}
      description={__(
        'This workflow is turned on. Once you apply changes, they’ll take effect immediately.',
        'yay-automation',
      )}
      confirmLabel={__('Apply changes', 'yay-automation')}
    />
  );
}

export function DiscardChangesDialog(props: ConfirmProps) {
  return (
    <ConfirmDialog
      {...props}
      title={__('Discard changes?', 'yay-automation')}
      description={__(
        'Your unapplied changes will be deleted. The workflow keeps running as it is now.',
        'yay-automation',
      )}
      confirmLabel={__('Discard changes', 'yay-automation')}
      destructive
    />
  );
}

export function TurnOffDialog(props: ConfirmProps) {
  return (
    <ConfirmDialog
      {...props}
      title={__('Turn off workflow', 'yay-automation')}
      description={__('If you turn this workflow off, it stops running on new orders.', 'yay-automation')}
      confirmLabel={__('Turn off', 'yay-automation')}
      cancelLabel={__('Dismiss', 'yay-automation')}
    />
  );
}
