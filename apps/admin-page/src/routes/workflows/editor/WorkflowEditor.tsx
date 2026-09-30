import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useStore } from '@tanstack/react-form';
import type { Workflow } from '@yay-automation/types';
import { useBlocker, useLocation, useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { useBeforeUnloadWarning } from '@/hooks/useBeforeUnloadWarning';
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
import { Button, LoadingButton } from '@/components/ui/button';
import { toast } from '@/components/ui/sonner';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
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
import { useWorkflowActionMutation } from './workflow.query';
import { ApplyChangesDialog, DiscardChangesDialog, MoreActionsMenu, TurnOnDialog } from './WorkflowActions';
import { WorkflowCanvas } from './WorkflowCanvas';

type ConfirmAction = 'turnOn' | 'apply' | 'discard';

/**
 * Edits one saved workflow; changes autosave. Mount with `key={workflow.id}` so switching starts fresh.
 * `workflow` is the server copy, kept current by the mutations; the form holds the graph being edited.
 */
export function WorkflowEditor({ workflow }: { workflow: Workflow }) {
  const form = useWorkflowForm(workflow);
  const autosave = useAutosave(form, workflow.id);
  const navigate = useNavigate();
  const location = useLocation();
  const [openNodeId, setOpenNodeId] = useState<string | null>(null);

  const isActive = workflow.status === 'active';
  // An active workflow's edits are unapplied changes as soon as they're made, not only once saved.
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
      // Issues with a `nodeId` mark that step (its settings, or `no_action` on the trigger). The
      // workflow-level `unlinked` issue has none, so unlinked steps aren't marked for it.
      issueNodeIds: new Set(issues.flatMap((issue) => (issue.nodeId ? [issue.nodeId] : []))),
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
      // The banner lists what the editor sees; if the server found something the editor didn't, say it.
      if (error instanceof ApiError && error.issues.length && issues.length) {
        setErrorMode(true);
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
    try {
      // Unsent edits are what's being discarded; only wait for a request already out.
      await autosave.settle();
      const saved = await discard.mutateAsync({ id: workflow.id });
      autosave.reset(saved.graph);
      setErrorMode(false);
      toast.success(__('Changes discarded', 'yay-automation'));
    } catch (error) {
      toast.error(error instanceof Error ? error.message : String(error));
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  // Show the error-mode hand-off once; a reload shouldn't reopen in error mode.
  useEffect(() => {
    if ((location.state as EditorNavState | null)?.showIssues) {
      navigate(location.pathname, { replace: true, state: null });
    }
  }, [location, navigate]);

  // Leaving waits for a pending save; only a failed save asks before leaving.
  const [leaveDialog, setLeaveDialog] = useState(false);
  const blocker = useBlocker(() => autosave.statusRef.current !== 'saved');
  // Latest blocker for the async callback; the effect runs once per block, not on every re-render.
  const blockerRef = useRef(blocker);
  useEffect(() => {
    blockerRef.current = blocker;
  });
  const { flush } = autosave;
  useEffect(() => {
    if (blocker.state !== 'blocked') return;
    void flush().then((ok) => {
      if (blockerRef.current.state !== 'blocked') return;
      if (ok) blockerRef.current.proceed();
      else setLeaveDialog(true);
    });
  }, [blocker.state, flush]);
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
              {(!isActive || hasDraft) && <Badge variant="primary-soft">{__('Draft', 'yay-automation')}</Badge>}
            </>
          }
          actions={
            <>
              <MoreActionsMenu workflow={workflow} onDeleted={autosave.stop} />
              {!isActive ? (
                <WithTooltip content={blockedByIssues && __('Fix the errors to turn on.', 'yay-automation')}>
                  <Button size="sm" disabled={busy || blockedByIssues} onClick={() => setConfirm('turnOn')}>
                    {__('Turn on workflow', 'yay-automation')}
                  </Button>
                </WithTooltip>
              ) : hasDraft ? (
                <>
                  <Button variant="outline" size="sm" disabled={busy} onClick={() => setConfirm('discard')}>
                    {__('Discard changes', 'yay-automation')}
                  </Button>
                  <WithTooltip content={blockedByIssues && __('Fix the errors to apply.', 'yay-automation')}>
                    <Button size="sm" disabled={busy || blockedByIssues} onClick={() => setConfirm('apply')}>
                      {__('Apply changes', 'yay-automation')}
                    </Button>
                  </WithTooltip>
                </>
              ) : (
                <LoadingButton variant="destructive" size="sm" loading={busy} disabled={busy} onClick={turnOff}>
                  {__('Turn off workflow', 'yay-automation')}
                </LoadingButton>
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

        <AlertDialog
          open={leaveDialog}
          onOpenChange={(open) => {
            if (open) return;
            setLeaveDialog(false);
            blocker.reset?.();
          }}
        >
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
                  setLeaveDialog(false);
                  autosave.stop();
                  blocker.proceed?.();
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

/**
 * Explains a disabled control. Disabled elements swallow pointer events, so the span carries the tooltip.
 * Same tree with or without content, so toggling it doesn't remount the control and drop focus.
 */
function WithTooltip({ content, children }: { content: ReactNode; children: ReactNode }) {
  return (
    <Tooltip open={content ? undefined : false}>
      <TooltipTrigger asChild>
        {/* Focusable only while the control inside is disabled and can't take focus itself. */}
        <span tabIndex={content ? 0 : undefined}>{children}</span>
      </TooltipTrigger>
      {content && <TooltipContent>{content}</TooltipContent>}
    </Tooltip>
  );
}
