import { useMemo, useRef, useState, type ReactNode } from 'react';
import { useStore } from '@tanstack/react-form';
import type { Workflow } from '@yay-automation/types';
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
import { createIssuesSelector, sameIssues } from '../validation';
import { EditorContext, type EditorState } from './editor.context';
import { EditorLayout } from './EditorLayout';
import { IssuesPopover } from './IssuesPopover';
import { NodeConfigSheet } from './NodeConfigSheet';
import { useWorkflowForm, WorkflowFormContext } from './useWorkflowForm';
import { removeNode } from './workflow.graph';
import { WorkflowCanvas } from './WorkflowCanvas';

/** Owns the form for one saved workflow. Mount with `key={workflow.id}` so switching starts fresh. */
export function WorkflowEditor({ workflow }: { workflow: Workflow }) {
  const form = useWorkflowForm(workflow);
  const [openNodeId, setOpenNodeId] = useState<string | null>(null);
  const nameInputRef = useRef<HTMLInputElement>(null);

  const isDirty = useStore(form.store, (state) => state.isDirty);
  // Reused across drag frames and compared by content, so the toolbar re-renders only when issues change.
  const [selectIssues] = useState(createIssuesSelector);
  const issues = useStore(form.store, (state) => selectIssues(state.values), sameIssues);
  // Drafts save with issues; turning on (or saving while on) needs none.
  const isActive = useStore(form.store, (state) => state.values.status === 'active');
  const saveBlocked = issues.length > 0 && isActive;

  const editorState = useMemo<EditorState>(
    () => ({
      openNodeId,
      // Issues with a `nodeId` mark that step (its settings, or `no_action` on the trigger). The
      // workflow-level `unlinked` issue has none, so unlinked steps aren't marked for it.
      errorNodeIds: new Set(issues.flatMap((issue) => (issue.nodeId ? [issue.nodeId] : []))),
      requestDelete: (nodeIds) =>
        form.setFieldValue('graph', (graph) => nodeIds.reduce((next, id) => removeNode(next, id), graph)),
    }),
    [form, openNodeId, issues],
  );

  // Reads live form state, so it always reflects the latest save.
  const leaveGuard = useRouteLeaveGuard(() => form.state.isDirty);
  useBeforeUnloadWarning(isDirty);

  return (
    <WorkflowFormContext.Provider value={form}>
      <EditorContext.Provider value={editorState}>
        <EditorLayout
          title={
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
          }
          actions={
            <div className="flex items-center gap-4">
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
                      disabled={!isDirty || isSubmitting || saveBlocked}
                      onClick={() => form.handleSubmit()}
                    >
                      {isSubmitting ? __('Saving…', 'yay-automation') : __('Save', 'yay-automation')}
                    </Button>
                  </WithTooltip>
                )}
              </form.Subscribe>
            </div>
          }
        >
          <WorkflowCanvas onNodeClick={setOpenNodeId} />
        </EditorLayout>

        <NodeConfigSheet nodeId={openNodeId} onClose={() => setOpenNodeId(null)} />

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
