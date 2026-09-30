import { useEffect, useMemo, useState } from 'react';
import { useStore } from '@tanstack/react-form';
import { useQueryClient } from '@tanstack/react-query';
import type { Workflow } from '@yay-automation/types';
import { useLocation, useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { useBeforeUnloadWarning } from '@/hooks/useBeforeUnloadWarning';
import { useRouteLeaveGuard } from '@/hooks/useRouteLeaveGuard';
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/sonner';
import { blocks } from '../blocks';
import { createIssuesSelector, sameIssues } from '../validation';
import { EditorContext, type EditorNavState, type EditorState } from './editor.context';
import { EditorLayout } from './EditorLayout';
import { IssueBanner } from './IssueBanner';
import { NodeConfigSheet } from './NodeConfigSheet';
import { SaveStatus } from './SaveStatus';
import { useAutosave } from './useAutosave';
import { useWorkflowForm, WorkflowFormContext } from './useWorkflowForm';
import { ApiError } from './workflow.api';
import { removeNode } from './workflow.graph';
import { useWorkflowActionMutation, workflowKeys } from './workflow.query';
import {
  ApplyChangesDialog,
  DiscardChangesDialog,
  MoreActionsMenu,
  RenameWorkflowDialog,
  TurnOffDialog,
  TurnOnDialog,
} from './WorkflowActions';
import { WorkflowCanvas } from './WorkflowCanvas';
import { workflowState, WorkflowStatusBadge } from './WorkflowStatusBadge';

type ConfirmAction = 'turnOn' | 'turnOff' | 'apply' | 'discard';

/**
 * Edits one saved workflow; changes autosave. Mount with `key={workflow.id}` so switching starts fresh.
 * `workflow` is the server copy, kept current by the mutations; the form holds the graph being edited.
 */
export function WorkflowEditor({ workflow }: { workflow: Workflow }) {
  const form = useWorkflowForm(workflow);
  const autosave = useAutosave(form, workflow.id);
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const location = useLocation();
  const [openNodeId, setOpenNodeId] = useState<string | null>(null);

  const isActive = workflow.status === 'active';
  // An active workflow's edits are unapplied changes as soon as they're made, not only once saved.
  // (If they end up matching the live graph, the server clears the draft and Apply reports 409.)
  const hasDraft = isActive && (workflow.draft !== null || autosave.status !== 'saved');

  // Reused across drag frames and compared by content, so the header re-renders only when issues change.
  const [selectIssues] = useState(createIssuesSelector);
  const issues = useStore(
    form.store,
    (state) => selectIssues({ name: workflow.name, graph: state.values.graph }),
    sameIssues,
  );

  // Blue "Review" until Turn on / Apply fails, then red "Error" + banner until one succeeds. Opened
  // from a failed Turn on elsewhere → starts in error mode.
  const [errorMode, setErrorMode] = useState(() => !!(location.state as EditorNavState | null)?.showIssues);
  const blockedByIssues = errorMode && issues.length > 0;

  const editorState = useMemo<EditorState>(
    () => ({
      openNodeId,
      // Marks each step an issue belongs to: its own settings, `no_action` on the trigger, and every
      // step `unlinked` lists (Images 17–18 mark unlinked steps too).
      issueNodeIds: new Set(issues.flatMap((issue) => (issue.nodeId ? [issue.nodeId] : (issue.nodeIds ?? [])))),
      issueMode: errorMode ? 'error' : 'review',
      requestDelete: (nodeIds) =>
        form.setFieldValue('graph', (graph) => nodeIds.reduce((next, id) => removeNode(next, id), graph)),
    }),
    [form, openNodeId, issues, errorMode],
  );

  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const [busy, setBusy] = useState(false);
  const activate = useWorkflowActionMutation('activate');
  const deactivate = useWorkflowActionMutation('deactivate');
  const apply = useWorkflowActionMutation('apply');
  const discard = useWorkflowActionMutation('discard');

  /** Saves pending edits, then runs `action`. Turn on / Apply failing on issues → error mode. */
  const run = async (action: () => Promise<Workflow>, success: string) => {
    setBusy(true);
    try {
      if (!(await autosave.flush())) {
        toast.error(__('Couldn’t save your latest changes. Try again.', 'yay-automation'));
        return;
      }
      await action();
      setErrorMode(false);
      toast.success(success);
    } catch (error) {
      if (error instanceof ApiError && error.code === 'yay_automation_no_draft') {
        // The edits ended up matching the live workflow, so the server dropped the draft. Refetch so
        // the header stops offering Apply.
        toast.info(__('There were no changes to apply.', 'yay-automation'));
        void queryClient.invalidateQueries({ queryKey: workflowKeys.detail(workflow.id) });
      } else if (error instanceof ApiError && error.issues.length) {
        setErrorMode(true);
        // The banner lists what the editor sees. If the server found anything else, say so here.
        const seen = new Set(issues.map((issue) => `${issue.code}|${issue.nodeId ?? ''}`));
        const unseen = error.issues.filter((issue) => !seen.has(`${issue.code}|${issue.nodeId ?? ''}`));
        if (unseen.length) {
          toast.error(error.message, { description: unseen.map((issue) => issue.message).join(' ') });
        }
      } else {
        toast.error(error instanceof Error ? error.message : String(error));
      }
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  const turnOn = () => run(() => activate.mutateAsync({ id: workflow.id }), __('Workflow turned on', 'yay-automation'));
  const applyChanges = () => run(() => apply.mutateAsync({ id: workflow.id }), __('Changes applied', 'yay-automation'));
  const turnOff = () =>
    run(() => deactivate.mutateAsync({ id: workflow.id }), __('Workflow turned off', 'yay-automation'));

  const discardChanges = async () => {
    setBusy(true);
    // Unsent edits are what's being discarded: stop sending, and wait out a request already out.
    await autosave.settle();
    try {
      const saved = await discard.mutateAsync({ id: workflow.id });
      autosave.reset(saved.graph);
      setErrorMode(false);
      toast.success(__('Changes discarded', 'yay-automation'));
    } catch (error) {
      // Nothing was discarded; the edits keep saving.
      autosave.resume();
      toast.error(error instanceof Error ? error.message : String(error));
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  const [renaming, setRenaming] = useState(false);

  // Show the error-mode hand-off once; a reload shouldn't reopen in error mode.
  useEffect(() => {
    if ((location.state as EditorNavState | null)?.showIssues) {
      navigate(location.pathname, { replace: true, state: null });
    }
  }, [location, navigate]);

  // Leaving waits for a pending save; only a failed save asks before leaving.
  const leaveGuard = useRouteLeaveGuard(() => autosave.statusRef.current !== 'saved', autosave.flush);
  useBeforeUnloadWarning(autosave.status !== 'saved');

  const lastChanged = (hasDraft && workflow.draftUpdatedAt) || workflow.updatedAt;

  return (
    <WorkflowFormContext.Provider value={form}>
      <EditorContext.Provider value={editorState}>
        <EditorLayout
          closeTo={`/workflows/${workflow.id}`}
          closeLabel={__('Close editor', 'yay-automation')}
          title={
            <>
              <span className="truncate font-medium">{workflow.name}</span>
              {/* Active: no badge, or Draft while there are unapplied changes. Off: Inactive or Draft. */}
              {isActive ? (
                hasDraft && <WorkflowStatusBadge state="draft" />
              ) : (
                <WorkflowStatusBadge state={workflowState(workflow)} />
              )}
            </>
          }
          actions={
            <>
              <MoreActionsMenu workflow={workflow} onDeleted={autosave.stop} />
              {!isActive ? (
                <Button size="sm" disabled={busy || blockedByIssues} onClick={() => setConfirm('turnOn')}>
                  {__('Turn on workflow', 'yay-automation')}
                </Button>
              ) : hasDraft ? (
                <>
                  <Button variant="outline" size="sm" disabled={busy} onClick={() => setConfirm('discard')}>
                    {__('Discard changes', 'yay-automation')}
                  </Button>
                  <Button size="sm" disabled={busy || blockedByIssues} onClick={() => setConfirm('apply')}>
                    {__('Apply changes', 'yay-automation')}
                  </Button>
                </>
              ) : (
                <Button variant="destructive" size="sm" disabled={busy} onClick={() => setConfirm('turnOff')}>
                  {__('Turn off workflow', 'yay-automation')}
                </Button>
              )}
            </>
          }
        >
          <div className="relative size-full">
            <WorkflowCanvas onNodeClick={setOpenNodeId} />
            {errorMode && (
              <IssueBanner
                issues={issues}
                labelFor={(nodeId) => {
                  const node = form.state.values.graph.nodes.find((n) => n.id === nodeId);
                  return node ? blocks[node.data.block].label : '';
                }}
                onView={setOpenNodeId}
                onRename={() => setRenaming(true)}
                className="absolute top-4 right-4 z-10"
              />
            )}
            <SaveStatus
              status={autosave.status}
              error={autosave.error}
              lastChanged={lastChanged}
              onRetry={() => void autosave.flush()}
              className="absolute right-4 bottom-6 z-10"
            />
          </div>
        </EditorLayout>

        <NodeConfigSheet nodeId={openNodeId} onClose={() => setOpenNodeId(null)} />

        <TurnOnDialog
          open={confirm === 'turnOn'}
          onOpenChange={(open) => !open && setConfirm(null)}
          onConfirm={turnOn}
          pending={busy}
        />
        <ApplyChangesDialog
          open={confirm === 'apply'}
          onOpenChange={(open) => !open && setConfirm(null)}
          onConfirm={applyChanges}
          pending={busy}
        />
        <DiscardChangesDialog
          open={confirm === 'discard'}
          onOpenChange={(open) => !open && setConfirm(null)}
          onConfirm={discardChanges}
          pending={busy}
        />
        <TurnOffDialog
          open={confirm === 'turnOff'}
          onOpenChange={(open) => !open && setConfirm(null)}
          onConfirm={turnOff}
          pending={busy}
        />

        {renaming && <RenameWorkflowDialog workflow={workflow} onClose={() => setRenaming(false)} />}

        <AlertDialog open={leaveGuard.showDialog} onOpenChange={(open) => !open && leaveGuard.cancelLeave()}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>{__('Leave without saving?', 'yay-automation')}</AlertDialogTitle>
              <AlertDialogDescription>
                {__('Your latest changes couldn’t be saved and will be lost.', 'yay-automation')}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>{__('Stay', 'yay-automation')}</AlertDialogCancel>
              {/* Not AlertDialogAction: that also fires onOpenChange(false), which would reset the blocker. */}
              <Button
                variant="destructive"
                onClick={() => {
                  autosave.stop();
                  leaveGuard.confirmLeave();
                }}
              >
                {__('Leave', 'yay-automation')}
              </Button>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </EditorContext.Provider>
    </WorkflowFormContext.Provider>
  );
}
