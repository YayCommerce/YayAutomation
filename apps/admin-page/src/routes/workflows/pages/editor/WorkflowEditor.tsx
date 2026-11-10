import { useEffect, useMemo, useState } from 'react';
import { useStore } from '@tanstack/react-form';
import { useQueryClient } from '@tanstack/react-query';
import type { StepId, Workflow, WorkflowIssue } from '@yaymatik/types';
import { AnimatePresence } from 'motion/react';
import { createPortal } from 'react-dom';
import { useLocation, useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { useBeforeUnloadWarning } from '@/hooks/useBeforeUnloadWarning';
import { useElementWidth } from '@/hooks/useElementWidth';
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
import { useHeaderSlot } from '@/routes/layout/useHeaderSlot';
import { ApiError, errorMessage } from '../../api/workflow.api';
import { useWorkflowActionMutation, workflowKeys } from '../../api/workflow.query';
import { removeNode, sameLogic, setNodeStep } from '../../model/graph';
import {
  createIssuesSelector,
  editedIssues,
  editedWarnings,
  issueText,
  newStepIssues,
  sameIssues,
  serverWarning,
  withServerIssues,
} from '../../model/validation';
import { useActivateWorkflow } from '../../shared/actions/useActivateWorkflow';
import {
  ActivateDialog,
  cancelledRunsText,
  DeactivateDialog,
  DeleteStepDialog,
  DiscardChangesDialog,
  MoreActionsMenu,
  RenameWorkflowDialog,
  UpdateDialog,
} from '../../shared/actions/WorkflowActions';
import { CreateWorkflowButton } from '../../shared/create/CreateWorkflowButton';
import { EditorLayout } from '../../shared/layout/EditorLayout';
import { type EditorNavState } from '../../shared/nav/editor.nav';
import { WarningBadge } from '../../shared/ui/WarningBadge';
import { workflowState, WorkflowStatusBadge } from '../../shared/ui/WorkflowStatusBadge';
import { EditorContext, type EditorState } from './editor.context';
import { IssueBanner } from './IssueBanner';
import { SaveStatus } from './SaveStatus';
import { panelWidthFor, StepPanel, StepPanelFrame } from './StepPanel';
import { useAutosave } from './useAutosave';
import { useIssueFeedback } from './useIssueFeedback';
import { useStepPanel } from './useStepPanel';
import { useWorkflowForm, WorkflowFormContext } from './useWorkflowForm';
import { WorkflowCanvas } from './WorkflowCanvas';

type ConfirmAction = 'activate' | 'deactivate' | 'update' | 'discard';

/**
 * Edits one saved workflow; changes autosave. Mount with `key={workflow.id}` so switching starts fresh.
 * `workflow` is the server copy, kept current by the mutations; the form holds the graph being edited.
 */
export function WorkflowEditor({ workflow }: { workflow: Workflow }) {
  const form = useWorkflowForm(workflow);
  const autosave = useAutosave(form, workflow.id, {
    issues: editedIssues(workflow),
    warnings: editedWarnings(workflow),
  });
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const location = useLocation();
  // The panel narrows with the canvas, so the graph keeps room beside it on laptops and tablets.
  const [canvasRef, canvasWidth] = useElementWidth<HTMLDivElement>();
  const panelWidth = panelWidthFor(canvasWidth);

  const isActive = workflow.status === 'active';
  // Once activated, edits go into a draft (active or not), and they're draft changes as soon as
  // they're made, not only once saved. (If they end up matching the saved content, the server clears
  // the draft and Update reports 409.) Moving a card isn't an edit. Never activated: edits change the
  // workflow itself.
  const editedLogic = useStore(
    form.store,
    (state) => workflow.everActivated && workflow.draft === null && !sameLogic(state.values.graph, workflow.graph),
  );
  const hasDraft = workflow.everActivated && (workflow.draft !== null || (autosave.status !== 'saved' && editedLogic));

  // Reused across drag frames and compared by content, so the header re-renders only when issues change.
  // Plus what only the server checks, from the last save, until the step changes again.
  const [selectIssues] = useState(createIssuesSelector);
  const issues = useStore(
    form.store,
    (state) =>
      withServerIssues(
        selectIssues({ name: workflow.name, graph: state.values.graph }),
        autosave.server,
        state.values.graph,
      ),
    sameIssues,
  );

  // Quiet → "review" (Needs setup) → red "error". Opened from a failed Activate elsewhere → starts red.
  const { errorMode, setErrorMode, feedbackFor, markVisited, markReview, issueModes } = useIssueFeedback(
    issues,
    !!(location.state as EditorNavState | null)?.showIssues,
  );
  const blockedByIssues = errorMode && issues.length > 0;

  const {
    panel,
    setPanel,
    showPanel,
    locked: panelLocked,
    formVersion,
    reset: resetPanel,
  } = useStepPanel(form, markVisited);
  const openNodeId = panel?.nodeId ?? null;
  const panelWarning = useStore(form.store, (state) =>
    openNodeId ? serverWarning(autosave.server, state.values.graph, openNodeId)?.message : undefined,
  );

  // An issue only the server finds arrives after the save, maybe once the step's panel is closed:
  // its card says "Needs setup" then, like a step left with an issue.
  const serverIssues = autosave.server.issues;
  useEffect(() => {
    markReview(
      issues.flatMap((issue) => (serverIssues.includes(issue) && issue.nodeId !== openNodeId ? [issue.nodeId!] : [])),
    );
  }, [issues, serverIssues, openNodeId, markReview]);

  const editorState = useMemo<EditorState>(
    () => ({
      openNodeId,
      openPanel: (nodeId, picking = false) => showPanel({ nodeId, picking }),
      issueModes,
      // Asks first (one dialog for several steps). The trigger can't be deleted.
      requestDelete: (nodeIds) => {
        const graph = form.state.values.graph;
        const ids = nodeIds.filter((id) => graph.nodes.find((node) => node.id === id)?.kind !== 'trigger');
        if (ids.length) {
          setStepsToDelete(ids);
          setDeleteCount(ids.length);
        }
      },
    }),
    [form, openNodeId, showPanel, issueModes],
  );
  const [stepsToDelete, setStepsToDelete] = useState<string[] | null>(null);
  // Kept after the dialog closes, so its title doesn't change while it fades out.
  const [deleteCount, setDeleteCount] = useState(1);
  const deleteSteps = () => {
    const ids = stepsToDelete ?? [];
    form.setFieldValue('graph', (graph) => ids.reduce((next, id) => removeNode(next, id), graph));
    setStepsToDelete(null);
    if (panel && ids.includes(panel.nodeId)) setPanel(null);
  };

  /** Sets a step's step, back to its form. Other steps the change breaks show "Needs setup". */
  const pickStep = (nodeId: string, stepId: StepId) => {
    const after = setNodeStep(form.state.values.graph, nodeId, stepId);
    const broken = newStepIssues(issues, { name: workflow.name, graph: after }, nodeId);
    form.setFieldValue('graph', after);
    markReview(broken);
    setPanel({ nodeId, picking: false });
  };

  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const [busy, setBusy] = useState(false);
  const { activate, deactivate } = useActivateWorkflow(workflow);
  const apply = useWorkflowActionMutation('apply');
  const discard = useWorkflowActionMutation('discard');

  /** Saves pending edits first; false (and a toast) when that fails. */
  const saveFirst = async () => {
    const saved = await autosave.flush();
    if (!saved) toast.error(__('Couldn’t save your latest changes. Try again.', 'yaymatik'));
    return saved;
  };

  /** A refusal for issues → error mode. The banner lists what the editor sees; anything else the server found goes in a toast. */
  const showRefusal = (refused: WorkflowIssue[], error: ApiError) => {
    setErrorMode(true);
    const seen = new Set(issues.map((issue) => `${issue.code}|${issue.nodeId ?? ''}`));
    const unseen = refused.filter((issue) => !seen.has(`${issue.code}|${issue.nodeId ?? ''}`));
    if (unseen.length) {
      const graph = form.state.values.graph;
      toast.error(error.message, { description: unseen.map((issue) => issueText(issue, graph)).join(' ') });
    }
  };

  /** Runs one header action with the buttons disabled, then closes its confirm. */
  const busyWhile = async (action: () => Promise<void>) => {
    setBusy(true);
    try {
      await action();
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  const runActivate = () =>
    busyWhile(() => activate({ before: saveFirst, onSuccess: () => setErrorMode(false), onIssues: showRefusal }));
  const runDeactivate = () => busyWhile(() => deactivate({ before: saveFirst, onSuccess: () => setErrorMode(false) }));

  const runUpdate = () =>
    busyWhile(async () => {
      if (!(await saveFirst())) return;
      try {
        const updated = await apply.mutateAsync({ id: workflow.id });
        setErrorMode(false);
        toast.success(__('Workflow updated', 'yaymatik'), { description: cancelledRunsText(updated.cancelledRuns) });
      } catch (error) {
        if (error instanceof ApiError && error.code === 'yaymatik_no_draft') {
          // The edits ended up matching the live workflow, so the server dropped the draft. Refetch so
          // the header stops offering Update.
          toast.info(__('There were no changes to update.', 'yaymatik'));
          void queryClient.invalidateQueries({ queryKey: workflowKeys.detail(workflow.id) });
        } else if (error instanceof ApiError && error.issues.length) {
          showRefusal(error.issues, error);
        } else {
          toast.error(errorMessage(error));
        }
      }
    });

  const discardChanges = async () => {
    setBusy(true);
    // Unsent edits are what's being discarded: stop sending, and wait out a request already out.
    await autosave.settle();
    try {
      const saved = await discard.mutateAsync({ id: workflow.id });
      autosave.reset(saved.graph, { issues: editedIssues(saved), warnings: editedWarnings(saved) });
      resetPanel(saved.graph);
      setErrorMode(false);
      toast.success(__('Changes discarded', 'yaymatik'));
    } catch (error) {
      // Nothing was discarded; the edits keep saving.
      autosave.resume();
      toast.error(errorMessage(error));
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
  const headerSlot = useHeaderSlot();

  const lastChanged = (hasDraft && workflow.draftUpdatedAt) || workflow.updatedAt;

  return (
    <WorkflowFormContext.Provider value={form}>
      <EditorContext.Provider value={editorState}>
        <EditorLayout
          closeTo={`/workflows/${workflow.id}`}
          closeLabel={__('Close editor', 'yaymatik')}
          title={
            <>
              <span className="truncate font-medium">{workflow.name}</span>
              {/* Edits not yet in the live workflow → Draft changes. Otherwise Draft (never activated),
                  Inactive, or no badge when active. */}
              {hasDraft ? (
                <WarningBadge>{__('Draft changes', 'yaymatik')}</WarningBadge>
              ) : (
                !isActive && <WorkflowStatusBadge state={workflowState(workflow)} />
              )}
            </>
          }
          actions={
            <>
              <MoreActionsMenu workflow={workflow} onDeleted={autosave.stop} />
              {hasDraft ? (
                <>
                  <Button variant="outline" size="sm" disabled={busy} onClick={() => setConfirm('discard')}>
                    {__('Discard changes', 'yaymatik')}
                  </Button>
                  {/* Only a running workflow asks first: updating an inactive one changes nothing live. */}
                  <Button
                    size="sm"
                    disabled={busy || blockedByIssues}
                    onClick={() => (isActive ? setConfirm('update') : void runUpdate())}
                  >
                    {__('Update', 'yaymatik')}
                  </Button>
                </>
              ) : !isActive ? (
                <Button size="sm" disabled={busy || blockedByIssues} onClick={() => setConfirm('activate')}>
                  {__('Activate', 'yaymatik')}
                </Button>
              ) : (
                <Button
                  variant="destructive-outline"
                  size="sm"
                  disabled={busy}
                  onClick={() => setConfirm('deactivate')}
                >
                  {__('Deactivate', 'yaymatik')}
                </Button>
              )}
            </>
          }
        >
          {/* Clipped, so the panel slides in from the canvas edge. */}
          <div ref={canvasRef} className="relative size-full overflow-hidden">
            <WorkflowCanvas
              panelInset={panel ? panelWidth + 24 : 0}
              onPaneClick={() => !panelLocked && showPanel(null)}
            />
            <AnimatePresence>
              {panel && (
                <StepPanelFrame key="panel" width={panelWidth}>
                  <StepPanel
                    // A reset (discard) starts the panel and its form over: forms keep their own values.
                    key={`${panel.nodeId}:${formVersion}`}
                    workflowId={workflow.id}
                    nodeId={panel.nodeId}
                    picking={panel.picking}
                    feedback={feedbackFor(panel.nodeId)}
                    issue={issues.find((issue) => issue.nodeId === panel.nodeId)}
                    warning={panelWarning}
                    closable={!panelLocked}
                    onPick={(stepId) => pickStep(panel.nodeId, stepId)}
                    onSwap={(picking) => setPanel({ nodeId: panel.nodeId, picking })}
                    onClose={() => showPanel(null)}
                  />
                </StepPanelFrame>
              )}
            </AnimatePresence>
            {errorMode && (
              <IssueBanner
                issues={issues}
                nodeFor={(nodeId) => form.state.values.graph.nodes.find((n) => n.id === nodeId)}
                onView={(nodeId) => showPanel({ nodeId, picking: false })}
                onRename={() => setRenaming(true)}
                // Clear of the step panel on the left.
                className="absolute top-4 right-4 z-10 min-w-60"
                style={panel ? { maxWidth: `calc(100% - ${panelWidth + 48}px)` } : undefined}
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

        <ActivateDialog
          open={confirm === 'activate'}
          onOpenChange={(open) => !open && setConfirm(null)}
          onConfirm={runActivate}
          pending={busy}
          name={workflow.name}
        />
        <UpdateDialog
          open={confirm === 'update'}
          onOpenChange={(open) => !open && setConfirm(null)}
          onConfirm={runUpdate}
          pending={busy}
          waitingRuns={workflow.waitingRuns}
        />
        <DiscardChangesDialog
          open={confirm === 'discard'}
          onOpenChange={(open) => !open && setConfirm(null)}
          onConfirm={discardChanges}
          pending={busy}
        />
        <DeleteStepDialog
          open={!!stepsToDelete}
          count={deleteCount}
          onOpenChange={(open) => !open && setStepsToDelete(null)}
          onConfirm={deleteSteps}
          pending={false}
        />
        <DeactivateDialog
          open={confirm === 'deactivate'}
          onOpenChange={(open) => !open && setConfirm(null)}
          onConfirm={runDeactivate}
          pending={busy}
          name={workflow.name}
          waitingRuns={workflow.waitingRuns}
        />

        {renaming && <RenameWorkflowDialog workflow={workflow} onClose={() => setRenaming(false)} />}
        {/* Saves first: a failed save leaves no new workflow behind a "Leave without saving?" Stay. */}
        {headerSlot && createPortal(<CreateWorkflowButton beforeCreate={autosave.flush} />, headerSlot)}

        <AlertDialog open={leaveGuard.showDialog} onOpenChange={(open) => !open && leaveGuard.cancelLeave()}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>{__('Leave without saving?', 'yaymatik')}</AlertDialogTitle>
              <AlertDialogDescription>
                {__('Your latest changes couldn’t be saved and will be lost.', 'yaymatik')}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>{__('Stay', 'yaymatik')}</AlertDialogCancel>
              {/* Not AlertDialogAction: that also fires onOpenChange(false), which would reset the blocker. */}
              <Button
                variant="destructive"
                onClick={() => {
                  autosave.stop();
                  leaveGuard.confirmLeave();
                }}
              >
                {__('Leave', 'yaymatik')}
              </Button>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </EditorContext.Provider>
    </WorkflowFormContext.Provider>
  );
}
