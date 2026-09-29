import { useCallback, useMemo, useRef, useState, type ReactNode } from 'react';
import { useStore } from '@tanstack/react-form';
import type { WorkflowDraft } from '@yay-automation/types';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { blocks } from '../blocks';
import { createIssuesSelector, onlyDeactivates, sameIssues } from '../validation';
import { EditorContext, type EditorState } from './editor.context';
import { EditorLayout } from './EditorLayout';
import { IssuesPopover } from './IssuesPopover';
import { NodeConfigSheet } from './NodeConfigSheet';
import { useWorkflowForm, WorkflowFormContext } from './useWorkflowForm';
import { deletionKind, removeNode } from './workflow.graph';
import { WorkflowCanvas } from './WorkflowCanvas';

/**
 * Owns the form for one workflow, saved or not. Mount with a `key` per workflow (or per template
 * for drafts) so switching starts fresh.
 */
export function WorkflowEditor({ workflow }: { workflow: WorkflowDraft }) {
  const form = useWorkflowForm(workflow);
  const [openNodeId, setOpenNodeId] = useState<string | null>(null);
  // Steps waiting for "delete its branches too?" confirmation (a condition with steps under it).
  const [pendingDeleteIds, setPendingDeleteIds] = useState<string[]>([]);
  const nameInputRef = useRef<HTMLInputElement>(null);

  const isDirty = useStore(form.store, (state) => state.isDirty);
  // Reused across drag frames and compared by content, so the toolbar re-renders only when issues change.
  const [selectIssues] = useState(createIssuesSelector);
  const issues = useStore(form.store, (state) => selectIssues(state.values), sameIssues);
  // Turning an active workflow off, and nothing else, is allowed even with issues (server agrees).
  const deactivateOnly = useStore(
    form.store,
    (state) => !!form.options.defaultValues && onlyDeactivates(state.values, form.options.defaultValues),
  );
  const saveBlocked = issues.length > 0 && !deactivateOnly;

  const removeNodes = useCallback(
    (nodeIds: string[]) =>
      form.setFieldValue('graph', (graph) => nodeIds.reduce((next, id) => removeNode(next, id), graph)),
    [form],
  );

  const editorState = useMemo<EditorState>(
    () => ({
      openNodeId,
      errorNodeIds: new Set(issues.flatMap((issue) => (issue.nodeId ? [issue.nodeId] : []))),
      requestDelete: (nodeIds) => {
        const kinds = nodeIds.map((id) => deletionKind(form.state.values.graph, id));
        const ids = nodeIds.filter((_, i) => kinds[i] !== 'none');
        if (kinds.includes('branches')) setPendingDeleteIds(ids);
        else if (ids.length) removeNodes(ids);
      },
    }),
    [form, openNodeId, issues, removeNodes],
  );

  const confirmDelete = () => {
    removeNodes(pendingDeleteIds);
    setPendingDeleteIds([]);
  };

  // Reads live form state, so the redirect after a first save (form just reset) isn't blocked.
  const leaveGuard = useRouteLeaveGuard(() => form.state.isDirty);
  useBeforeUnloadWarning(isDirty);

  return (
    <WorkflowFormContext.Provider value={form}>
      <EditorContext.Provider value={editorState}>
        <EditorLayout
          toolbar={
            <>
              <form.Field name="name">
                {(field) => (
                  <Input
                    ref={nameInputRef}
                    className="h-8 w-72 max-w-full font-medium"
                    value={field.state.value}
                    onChange={(event) => field.handleChange(event.target.value)}
                    onBlur={field.handleBlur}
                    aria-label={__('Workflow name', 'yay-automation')}
                    aria-invalid={issues.some((issue) => issue.code === 'name_required')}
                  />
                )}
              </form.Field>

              <div className="ml-auto flex shrink-0 items-center gap-4">
                <form.Field name="status">
                  {(field) => {
                    const active = field.state.value === 'active';
                    // Turning on needs a workflow without issues. Turning off is always possible, and a
                    // save that only turns it off goes through even with issues.
                    const disabled = !active && issues.length > 0;
                    return (
                      <WithTooltip content={disabled && __('Fix the issues before activating.', 'yay-automation')}>
                        <div className="flex items-center gap-2">
                          <Switch
                            id="workflow-active"
                            size="sm"
                            checked={active}
                            disabled={disabled}
                            onCheckedChange={(checked) => field.handleChange(checked ? 'active' : 'inactive')}
                          />
                          <Label htmlFor="workflow-active" className="text-sm">
                            {active ? __('Active', 'yay-automation') : __('Inactive', 'yay-automation')}
                          </Label>
                        </div>
                      </WithTooltip>
                    );
                  }}
                </form.Field>

                <IssuesPopover
                  issues={issues}
                  labelFor={(nodeId) => {
                    const node = form.state.values.graph.nodes.find((n) => n.id === nodeId);
                    return node ? blocks[node.data.block].label : '';
                  }}
                  onSelectNode={setOpenNodeId}
                  onSelectWorkflowIssue={(issue) => issue.code === 'name_required' && nameInputRef.current?.focus()}
                />

                {/* Subscribe re-renders only this button when dirty/submitting flip. */}
                <form.Subscribe selector={(state) => [state.isDirty, state.isSubmitting] as const}>
                  {([isDirty, isSubmitting]) => (
                    <WithTooltip content={saveBlocked && __('Fix the issues before saving.', 'yay-automation')}>
                      <Button
                        size="sm"
                        // An unsaved draft can be saved as-is; a saved workflow only when changed.
                        disabled={!(isDirty || !workflow.id) || isSubmitting || saveBlocked}
                        onClick={() => form.handleSubmit()}
                      >
                        {isSubmitting ? __('Saving…', 'yay-automation') : __('Save', 'yay-automation')}
                      </Button>
                    </WithTooltip>
                  )}
                </form.Subscribe>
              </div>
            </>
          }
        >
          <WorkflowCanvas onNodeClick={setOpenNodeId} />
        </EditorLayout>

        <NodeConfigSheet nodeId={openNodeId} onClose={() => setOpenNodeId(null)} />

        <AlertDialog open={pendingDeleteIds.length > 0} onOpenChange={(open) => !open && setPendingDeleteIds([])}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>{__('Delete this condition and its branches?', 'yay-automation')}</AlertDialogTitle>
              <AlertDialogDescription>
                {__('Every step under this condition is deleted too.', 'yay-automation')}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>{__('Cancel', 'yay-automation')}</AlertDialogCancel>
              <Button variant="destructive" onClick={confirmDelete}>
                {__('Delete', 'yay-automation')}
              </Button>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>

        <AlertDialog open={leaveGuard.showDialog} onOpenChange={(open) => !open && leaveGuard.cancelLeave()}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>{__('Leave without saving?', 'yay-automation')}</AlertDialogTitle>
              <AlertDialogDescription>
                {__('Your changes to this workflow will be lost.', 'yay-automation')}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>{__('Stay', 'yay-automation')}</AlertDialogCancel>
              {/* Not AlertDialogAction: that also fires onOpenChange(false), which would reset the blocker. */}
              <Button variant="destructive" onClick={leaveGuard.confirmLeave}>
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
