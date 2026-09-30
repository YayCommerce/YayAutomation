import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import type { Workflow } from '@yay-automation/types';
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
import { BulkActionBox } from '@/components/ui/bulk-actions';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { toast } from '@/components/ui/sonner';
import { ApiError, deactivateWorkflow, deleteWorkflow, getWorkflow } from './editor/workflow.api';
import { workflowKeys } from './editor/workflow.query';
import { ConfirmDialog } from './editor/WorkflowActions';

type Dialog = 'turnOff' | 'delete' | 'deleteBlocked';

/**
 * Bulk actions bar for the workflows list (shown with 2+ selected): clear, "N selected", Delete, and
 * Turn off when any selected workflow is on. Requests go one per workflow through the single
 * endpoints, so the server's rules (e.g. no deleting an active workflow) still apply to each.
 */
export function WorkflowBulkActions({ selected, onClear }: { selected: Workflow[]; onClear: () => void }) {
  const queryClient = useQueryClient();
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [pending, setPending] = useState(false);
  const active = selected.filter((workflow) => workflow.status === 'active');

  const refresh = () => queryClient.invalidateQueries({ queryKey: workflowKeys.all });

  const turnOff = async () => {
    setPending(true);
    let done = 0;
    let failure: string | null = null;
    for (const workflow of active) {
      try {
        await deactivateWorkflow(workflow.id);
        done++;
      } catch (error) {
        failure = error instanceof Error ? error.message : String(error);
      }
    }
    await refresh();
    setPending(false);
    setDialog(null);
    if (done) {
      /* translators: %d: number of workflows */
      toast.success(sprintf(_n('%d workflow turned off', '%d workflows turned off', done, 'yay-automation'), done));
      onClear();
    }
    if (failure) toast.error(failure);
  };

  const remove = async () => {
    setPending(true);
    // All or nothing: this list can be stale (a workflow turned on in another tab), so re-read every
    // selected workflow first and delete none if any is on. The server's 409 stays as the last guard.
    try {
      const current = await Promise.all(selected.map((workflow) => getWorkflow(workflow.id)));
      if (current.some((workflow) => workflow.status === 'active')) {
        await refresh();
        setPending(false);
        setDialog('deleteBlocked');
        return;
      }
    } catch (error) {
      setPending(false);
      setDialog(null);
      toast.error(error instanceof Error ? error.message : String(error));
      return;
    }

    let done = 0;
    let refused = false;
    let failure: string | null = null;
    for (const workflow of selected) {
      try {
        await deleteWorkflow(workflow.id);
        queryClient.removeQueries({ queryKey: workflowKeys.detail(workflow.id) });
        done++;
      } catch (error) {
        // Turned on between the check above and this request: the server keeps it.
        if (error instanceof ApiError && error.code === 'yay_automation_delete_active') refused = true;
        else failure = error instanceof Error ? error.message : String(error);
      }
    }
    await refresh();
    setPending(false);
    if (done) {
      /* translators: %d: number of workflows */
      toast.success(sprintf(_n('%d workflow deleted', '%d workflows deleted', done, 'yay-automation'), done));
    }
    if (failure) toast.error(failure);
    if (refused) {
      setDialog('deleteBlocked');
    } else {
      setDialog(null);
      if (done) onClear();
    }
  };

  return (
    <>
      <BulkActionBox selected={selected.length} onClose={onClear}>
        <span className="text-sm whitespace-nowrap">
          {sprintf(
            /* translators: %d: number of selected workflows */
            __('%d selected', 'yay-automation'),
            selected.length,
          )}
        </span>
        <Separator orientation="vertical" className="ml-2 h-5!" />
        {active.length > 0 && (
          <Button variant="ghost" size="sm" onClick={() => setDialog('turnOff')}>
            {__('Turn off', 'yay-automation')}
          </Button>
        )}
        <Button
          variant="ghost"
          size="sm"
          className="text-destructive hover:text-destructive"
          // Any selected workflow still on → explain instead of offering to delete.
          onClick={() => setDialog(active.length ? 'deleteBlocked' : 'delete')}
        >
          {__('Delete', 'yay-automation')}
        </Button>
      </BulkActionBox>

      <ConfirmDialog
        open={dialog === 'turnOff'}
        onOpenChange={(open) => !open && setDialog(null)}
        onConfirm={() => void turnOff()}
        pending={pending}
        title={sprintf(
          /* translators: %d: number of workflows */
          _n('Turn off %d workflow', 'Turn off %d workflows', active.length, 'yay-automation'),
          active.length,
        )}
        description={_n(
          'If you turn this workflow off, the runs in progress will be cancelled.',
          'If you turn these workflows off, the runs in progress will be cancelled.',
          active.length,
          'yay-automation',
        )}
        confirmLabel={__('Turn off', 'yay-automation')}
        cancelLabel={__('Dismiss', 'yay-automation')}
      />
      <ConfirmDialog
        open={dialog === 'delete'}
        onOpenChange={(open) => !open && setDialog(null)}
        onConfirm={() => void remove()}
        pending={pending}
        title={sprintf(
          /* translators: %d: number of workflows */
          _n('Delete %d workflow?', 'Delete %d workflows?', selected.length, 'yay-automation'),
          selected.length,
        )}
        description={__('These workflows will be permanently deleted.', 'yay-automation')}
        confirmLabel={__('Delete', 'yay-automation')}
        destructive
      />
      <AlertDialog open={dialog === 'deleteBlocked'} onOpenChange={(open) => !open && setDialog(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{__('There was a problem deleting some workflows', 'yay-automation')}</AlertDialogTitle>
            <AlertDialogDescription>
              {__(
                'Because one or more workflows are still turned on, they couldn’t be deleted. Turn them off and try again.',
                'yay-automation',
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{__('Back', 'yay-automation')}</AlertDialogCancel>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
