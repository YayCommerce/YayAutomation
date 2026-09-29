import { useState, type ReactNode } from 'react';
import { useStore } from '@tanstack/react-form';
import type { WorkflowDraft } from '@yay-automation/types';
import * as v from 'valibot';
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
import { allConfigured } from '../blocks';
import { EditorLayout } from './EditorLayout';
import { NodeConfigSheet } from './NodeConfigSheet';
import { useWorkflowForm, WorkflowFormContext } from './useWorkflowForm';
import { WorkflowCanvas } from './WorkflowCanvas';

const nameRequired = __('Enter a workflow name.', 'yay-automation');

const nameSchema = v.pipe(
  v.string(),
  v.check((name) => name.trim() !== '', nameRequired),
);

/**
 * Owns the form for one workflow, saved or not. Mount with a `key` per workflow (or per template
 * for drafts) so switching starts fresh.
 */
export function WorkflowEditor({ workflow }: { workflow: WorkflowDraft }) {
  const form = useWorkflowForm(workflow);
  const [openNodeId, setOpenNodeId] = useState<string | null>(null);

  const isDirty = useStore(form.store, (state) => state.isDirty);
  // Primitive selectors: components re-render only when these flip, not on every drag frame.
  const configured = useStore(form.store, (state) => allConfigured(state.values.graph));
  const nameEmpty = useStore(form.store, (state) => !state.values.name.trim());
  const isActive = useStore(form.store, (state) => state.values.status === 'active');
  // Why Save is off even though there are changes. Null when nothing blocks it.
  const saveBlocker = nameEmpty
    ? nameRequired
    : isActive && !configured
      ? __('Finish setting up every step, or turn the workflow off.', 'yay-automation')
      : null;

  // Reads live form state, so the redirect after a first save (form just reset) isn't blocked.
  const leaveGuard = useRouteLeaveGuard(() => form.state.isDirty);
  useBeforeUnloadWarning(isDirty);

  return (
    <WorkflowFormContext.Provider value={form}>
      <EditorLayout
        toolbar={
          <>
            <form.Field name="name" validators={{ onChange: nameSchema }}>
              {(field) => (
                <Input
                  className="h-8 w-72 max-w-full font-medium"
                  value={field.state.value}
                  onChange={(event) => field.handleChange(event.target.value)}
                  onBlur={field.handleBlur}
                  aria-label={__('Workflow name', 'yay-automation')}
                  aria-invalid={!field.state.meta.isValid}
                />
              )}
            </form.Field>

            <div className="ml-auto flex shrink-0 items-center gap-4">
              <form.Field name="status">
                {(field) => {
                  const active = field.state.value === 'active';
                  // Turning off is always allowed; turning on needs every step configured.
                  const disabled = !active && !configured;
                  return (
                    <WithTooltip content={disabled && __('Configure every step before activating.', 'yay-automation')}>
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

              {/* Subscribe re-renders only this button when dirty/submitting flip. */}
              <form.Subscribe selector={(state) => [state.isDirty, state.isSubmitting] as const}>
                {([isDirty, isSubmitting]) => (
                  <WithTooltip content={(isDirty || !workflow.id) && saveBlocker}>
                    <Button
                      size="sm"
                      // An unsaved draft can be saved as-is; a saved workflow only when changed.
                      disabled={!(isDirty || !workflow.id) || isSubmitting || !!saveBlocker}
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
