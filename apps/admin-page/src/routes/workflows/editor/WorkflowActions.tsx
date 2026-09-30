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
import { useDeleteWorkflowMutation, useUpdateWorkflowMutation } from './workflow.query';

/** "More actions" menu: Rename and Delete. Delete returns to the list. Shared by the Overview and editor. */
export function MoreActionsMenu({ workflow }: { workflow: Workflow }) {
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
        onDeleted={() => navigate('/workflows')}
      />
    </>
  );
}

function RenameWorkflowDialog({ workflow, onClose }: { workflow: Workflow; onClose: () => void }) {
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

/** Confirms and deletes `workflow`; closed while it's null. Stays open while deleting, so a failure can be retried. */
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

  const confirm = () => {
    if (!workflow) return;
    deleteMutation.mutate(workflow.id, {
      onSuccess: () => {
        toast.success(__('Workflow deleted', 'yay-automation'));
        onClose();
        onDeleted?.();
      },
      onError: (error) => toast.error(error.message),
    });
  };

  return (
    <AlertDialog open={!!workflow} onOpenChange={(open) => !open && !deleteMutation.isPending && onClose()}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>
            {sprintf(
              /* translators: %s: workflow name */
              __('Delete “%s”?', 'yay-automation'),
              workflow?.name ?? '',
            )}
          </AlertDialogTitle>
          <AlertDialogDescription>
            {__('The workflow stops running right away. This can’t be undone.', 'yay-automation')}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={deleteMutation.isPending}>{__('Cancel', 'yay-automation')}</AlertDialogCancel>
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
      </AlertDialogContent>
    </AlertDialog>
  );
}

/** "Ready to turn on…?" confirm. `onConfirm` runs the activation; the dialog stays open while `pending`. */
export function TurnOnDialog({
  open,
  onOpenChange,
  onConfirm,
  pending,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
  pending: boolean;
}) {
  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{__('Ready to turn on your automation workflow?', 'yay-automation')}</DialogTitle>
          <DialogDescription>
            {__('Turning on the workflow will activate the automation.', 'yay-automation')}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline" disabled={pending}>
              {__('Cancel', 'yay-automation')}
            </Button>
          </DialogClose>
          <LoadingButton loading={pending} disabled={pending} onClick={onConfirm}>
            {__('Turn on', 'yay-automation')}
          </LoadingButton>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
