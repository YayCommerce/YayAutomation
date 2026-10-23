import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import type { Workflow } from '@yaymatik/types';
import { __, _n, sprintf } from '@wordpress/i18n';

import { AlertDialog, AlertDialogContent } from '@/components/ui/alert-dialog';
import { BulkActionBox } from '@/components/ui/bulk-actions';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { toast } from '@/components/ui/sonner';
import { ApiError, errorMessage, getWorkflow } from '../../api/workflow.api';
import { useDeleteWorkflowMutation, useWorkflowActionMutation, workflowKeys } from '../../api/workflow.query';
import {
  cancelledRunsText,
  ConfirmDialog,
  DeactivateDialog,
  DeleteBlockedContent,
} from '../../shared/actions/WorkflowActions';

type Dialog = 'deactivate' | 'delete' | 'deleteBlocked';

/**
 * Bulk actions bar for the workflows list (shown with 2+ selected): clear, "N selected", Delete, and
 * Deactivate when any selected workflow is active. Requests go one per workflow, in parallel, through the
 * same mutations as the single actions, so the server's rules (e.g. no deleting an active workflow)
 * and the cache updates stay the same.
 *
 * `onDone(keepIds)`: after an action, only the workflows it couldn't handle stay selected.
 */
export function WorkflowBulkActions({
  selected,
  onClear,
  onDone,
}: {
  selected: Workflow[];
  onClear: () => void;
  onDone: (keepIds: string[]) => void;
}) {
  const queryClient = useQueryClient();
  const deactivate = useWorkflowActionMutation('deactivate');
  const remove = useDeleteWorkflowMutation();
  const [dialog, setDialog] = useState<Dialog | null>(null);
  // Fixed when a dialog opens: the list refreshes while it's still open (and closing), which would
  // otherwise change its counts under the user.
  const [targets, setTargets] = useState<Workflow[]>([]);
  const [pending, setPending] = useState(false);
  const active = selected.filter((workflow) => workflow.status === 'active');

  const open = (next: Dialog, workflows: Workflow[]) => {
    setTargets(workflows);
    setDialog(next);
  };
  const refresh = () => queryClient.invalidateQueries({ queryKey: workflowKeys.all });

  const deactivateAll = async () => {
    setPending(true);
    const results = await Promise.allSettled(targets.map((workflow) => deactivate.mutateAsync({ id: workflow.id })));
    const failed = targets.filter((_, i) => results[i].status === 'rejected');
    const done = targets.length - failed.length;
    await refresh();
    setPending(false);
    setDialog(null);
    if (done) {
      const cancelled = results.reduce(
        (sum, result) => sum + (result.status === 'fulfilled' ? (result.value.cancelledRuns ?? 0) : 0),
        0,
      );
      toast.success(
        /* translators: %d: number of workflows */
        sprintf(_n('%d workflow deactivated', '%d workflows deactivated', done, 'yaymatik'), done),
        { description: cancelledRunsText(cancelled) },
      );
    }
    if (failed.length) {
      const reason = results.find((result) => result.status === 'rejected')?.reason;
      toast.error(
        sprintf(
          /* translators: %d: number of workflows */
          _n('%d workflow couldn’t be deactivated', '%d workflows couldn’t be deactivated', failed.length, 'yaymatik'),
          failed.length,
        ),
        { description: errorMessage(reason) },
      );
    }
    onDone(failed.map((workflow) => workflow.id));
  };

  const deleteAll = async () => {
    setPending(true);
    // All or nothing: this list can be stale (a workflow activated in another tab), so re-read every
    // selected workflow first and delete none if any is on. The server's 409 stays as the last guard.
    try {
      const current = await Promise.all(targets.map((workflow) => getWorkflow(workflow.id)));
      if (current.some((workflow) => workflow.status === 'active')) {
        await refresh();
        setPending(false);
        setDialog('deleteBlocked');
        return;
      }
    } catch (error) {
      setPending(false);
      setDialog(null);
      toast.error(errorMessage(error));
      return;
    }

    const results = await Promise.allSettled(targets.map((workflow) => remove.mutateAsync(workflow.id)));
    // Activated between the check above and its delete: the server keeps it.
    const refused = targets.filter((_, i) => {
      const result = results[i];
      return (
        result.status === 'rejected' &&
        result.reason instanceof ApiError &&
        result.reason.code === 'yaymatik_delete_active'
      );
    });
    const failed = targets.filter((_, i) => results[i].status === 'rejected' && !refused.includes(targets[i]));
    const done = targets.length - refused.length - failed.length;
    await refresh();
    setPending(false);
    setDialog(refused.length ? 'deleteBlocked' : null);
    if (done) {
      /* translators: %d: number of workflows */
      toast.success(sprintf(_n('%d workflow deleted', '%d workflows deleted', done, 'yaymatik'), done));
    }
    if (failed.length) {
      const index = targets.indexOf(failed[0]);
      const result = results[index];
      toast.error(
        sprintf(
          /* translators: %d: number of workflows */
          _n('%d workflow couldn’t be deleted', '%d workflows couldn’t be deleted', failed.length, 'yaymatik'),
          failed.length,
        ),
        { description: result.status === 'rejected' ? errorMessage(result.reason) : undefined },
      );
    }
    onDone([...refused, ...failed].map((workflow) => workflow.id));
  };

  return (
    <>
      <BulkActionBox selected={selected.length} onClose={onClear}>
        <span className="text-sm whitespace-nowrap">
          {sprintf(
            /* translators: %d: number of selected workflows */
            __('%d selected', 'yaymatik'),
            selected.length,
          )}
        </span>
        <Separator orientation="vertical" className="ml-2 h-5!" />
        {active.length > 0 && (
          <Button variant="ghost" size="sm" onClick={() => open('deactivate', active)}>
            {__('Deactivate', 'yaymatik')}
          </Button>
        )}
        <Button
          variant="ghost"
          size="sm"
          className="text-destructive hover:text-destructive"
          // Any selected workflow still on → explain instead of offering to delete.
          onClick={() => open(active.length ? 'deleteBlocked' : 'delete', selected)}
        >
          {__('Delete', 'yaymatik')}
        </Button>
      </BulkActionBox>

      <DeactivateDialog
        open={dialog === 'deactivate'}
        onOpenChange={(isOpen) => !isOpen && setDialog(null)}
        onConfirm={() => void deactivateAll()}
        pending={pending}
        count={targets.length}
        waitingRuns={targets.reduce((sum, workflow) => sum + workflow.waitingRuns, 0)}
      />
      <ConfirmDialog
        open={dialog === 'delete'}
        onOpenChange={(isOpen) => !isOpen && setDialog(null)}
        onConfirm={() => void deleteAll()}
        pending={pending}
        title={sprintf(
          /* translators: %d: number of workflows */
          _n('Delete %d workflow?', 'Delete %d workflows?', targets.length, 'yaymatik'),
          targets.length,
        )}
        description={__('These workflows will be permanently deleted.', 'yaymatik')}
        confirmLabel={__('Delete', 'yaymatik')}
        destructive
      />
      <AlertDialog open={dialog === 'deleteBlocked'} onOpenChange={(isOpen) => !isOpen && setDialog(null)}>
        <AlertDialogContent>
          <DeleteBlockedContent several />
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
