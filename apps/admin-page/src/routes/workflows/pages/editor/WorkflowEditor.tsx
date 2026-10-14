import { useEffect, useMemo, useState } from 'react';
import { useStore } from '@tanstack/react-form';
import { useQueryClient } from '@tanstack/react-query';
import type { Workflow } from '@yay-automation/types';
import { AnimatePresence } from 'motion/react';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/sonner';
import { ApiError } from '../../api/workflow.api';
import { useWorkflowActionMutation, workflowKeys } from '../../api/workflow.query';
import { removeNode } from '../../model/graph';
import { createIssuesSelector, sameIssues } from '../../model/validation';
import {
  ApplyChangesDialog,
  DeleteStepDialog,
  DiscardChangesDialog,
  MoreActionsMenu,
  RenameWorkflowDialog,
  TurnOffDialog,
  TurnOnDialog,
} from '../../shared/actions/WorkflowActions';
import { EditorLayout } from '../../shared/layout/EditorLayout';
import { type EditorNavState } from '../../shared/nav/editor.nav';
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
  // The panel narrows with the canvas, so the graph keeps room beside it on laptops and tablets.
  const [canvasRef, canvasWidth] = useElementWidth<HTMLDivElement>();
  const panelWidth = panelWidthFor(canvasWidth);

  const isActive = workflow.status === 'active';
  // Once activated, edits go into a draft (active or not), and they're draft changes as soon as
  // they're made, not only once saved. (If they end up matching the saved content, the server clears
  // the draft and Update reports 409.) Never activated: edits change the workflow itself.
  const hasDraft = workflow.everActivated && (workflow.draft !== null || autosave.status !== 'saved');

  // Reused across drag frames and compared by content, so the header re-renders only when issues change.
  const [selectIssues] = useState(createIssuesSelector);
  const issues = useStore(
    form.store,
    (state) => selectIssues({ name: workflow.name, graph: state.values.graph }),
    sameIssues,
  );

  // Quiet → "review" (Needs setup) → red "error". Opened from a failed Activate elsewhere → starts red.
  const { errorMode, setErrorMode, feedbackFor, markVisited, issueModes } = useIssueFeedback(
    issues,
    !!(location.state as EditorNavState | null)?.showIssues,
  );
  const blockedByIssues = errorMode && issues.length > 0;

  const { panel, setPanel, showPanel, locked: panelLocked } = useStepPanel(form, markVisited);
  const openNodeId = panel?.nodeId ?? null;

  const editorState = useMemo<EditorState>(
    () => ({
      openNodeId,
      openPanel: (nodeId, picking = false) => showPanel({ nodeId, picking }),
      issueModes,
      // Asks first (one dialog for several steps). The trigger can't be deleted.
      requestDelete: (nodeIds) => {
        const graph = form.state.values.graph;
        const ids = nodeIds.filter((id) => graph.nodes.find((node) => node.id === id)?.kind !== 'trigger');
        if (ids.length) setStepsToDelete(ids);
      },
    }),
    [form, openNodeId, showPanel, issueModes],
  );
  const [stepsToDelete, setStepsToDelete] = useState<string[] | null>(null);
  const deleteSteps = () => {
    const ids = stepsToDelete ?? [];
    form.setFieldValue('graph', (graph) => ids.reduce((next, id) => removeNode(next, id), graph));
    setStepsToDelete(null);
    if (panel && ids.includes(panel.nodeId)) setPanel(null);
  };

  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const [busy, setBusy] = useState(false);
  const activate = useWorkflowActionMutation('activate');
  const deactivate = useWorkflowActionMutation('deactivate');
  const apply = useWorkflowActionMutation('apply');
  const discard = useWorkflowActionMutation('discard');

  /** Saves pending edits, then runs `action`. Activate / Update failing on issues → error mode. */
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
        // the header stops offering Update.
        toast.info(__('There were no changes to update.', 'yay-automation'));
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

  const turnOn = () => run(() => activate.mutateAsync({ id: workflow.id }), __('Workflow activated', 'yay-automation'));
  const applyChanges = () =>
    run(() => apply.mutateAsync({ id: workflow.id }), __('Workflow updated', 'yay-automation'));
  const turnOff = () =>
    run(() => deactivate.mutateAsync({ id: workflow.id }), __('Workflow deactivated', 'yay-automation'));

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
              {/* Edits not yet in the live workflow → Draft changes. Otherwise Draft (never activated),
                  Inactive, or no badge when active. */}
              {hasDraft ? (
                <Badge variant="warning-soft">{__('Draft changes', 'yay-automation')}</Badge>
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
                    {__('Discard changes', 'yay-automation')}
                  </Button>
                  {/* Only a running workflow asks first: updating an inactive one changes nothing live. */}
                  <Button
                    size="sm"
                    disabled={busy || blockedByIssues}
                    onClick={() => (isActive ? setConfirm('apply') : void applyChanges())}
                  >
                    {__('Update', 'yay-automation')}
                  </Button>
                </>
              ) : !isActive ? (
                <Button size="sm" disabled={busy || blockedByIssues} onClick={() => setConfirm('turnOn')}>
                  {__('Activate', 'yay-automation')}
                </Button>
              ) : (
                <Button variant="destructive" size="sm" disabled={busy} onClick={() => setConfirm('turnOff')}>
                  {__('Deactivate', 'yay-automation')}
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
                    key={panel.nodeId}
                    nodeId={panel.nodeId}
                    picking={panel.picking}
                    feedback={feedbackFor(panel.nodeId)}
                    closable={!panelLocked}
                    onPicked={() => setPanel({ nodeId: panel.nodeId, picking: false })}
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

        <TurnOnDialog
          open={confirm === 'turnOn'}
          onOpenChange={(open) => !open && setConfirm(null)}
          onConfirm={turnOn}
          pending={busy}
          hasDraft={hasDraft}
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
        <DeleteStepDialog
          open={!!stepsToDelete}
          onOpenChange={(open) => !open && setStepsToDelete(null)}
          onConfirm={deleteSteps}
          pending={false}
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
