import { useRef, useState } from 'react';
import type { WorkflowIssue } from '@yay-automation/types';
import { CircleAlert } from 'lucide-react';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

/**
 * "N issues" button next to Save. Lists what blocks saving; picking a step issue opens that step,
 * picking a workflow-level one runs `onSelectWorkflowIssue` (e.g. focus the name field).
 */
export function IssuesPopover({
  issues,
  labelFor,
  onSelectNode,
  onSelectWorkflowIssue,
}: {
  issues: WorkflowIssue[];
  /** Block label of a step, used as the prefix of its issues. Called only while the list is open. */
  labelFor: (nodeId: string) => string;
  onSelectNode: (nodeId: string) => void;
  onSelectWorkflowIssue: (issue: WorkflowIssue) => void;
}) {
  const [open, setOpen] = useState(false);
  // The picked issue runs after the popover has closed: Radix moves focus back to the trigger on
  // close, which would otherwise undo focusing the name field.
  const picked = useRef<WorkflowIssue | null>(null);
  if (!issues.length) return null;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="destructive-soft" size="sm">
          <CircleAlert />
          {sprintf(
            /* translators: %d: number of issues */
            _n('%d issue', '%d issues', issues.length, 'yay-automation'),
            issues.length,
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="end"
        className="w-80 p-2"
        onCloseAutoFocus={(event) => {
          const issue = picked.current;
          picked.current = null;
          if (!issue) return;
          event.preventDefault();
          if (issue.nodeId) onSelectNode(issue.nodeId);
          else onSelectWorkflowIssue(issue);
        }}
      >
        <div className="text-muted-foreground px-2 py-1 text-xs font-medium">
          {__('Fix these before saving', 'yay-automation')}
        </div>
        <ul>
          {issues.map((issue, index) => (
            <li key={`${issue.code}-${issue.nodeId ?? ''}-${index}`}>
              <button
                type="button"
                className="hover:bg-accent focus-visible:bg-accent flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left text-sm outline-none"
                onClick={() => {
                  picked.current = issue;
                  setOpen(false);
                }}
              >
                <CircleAlert className="text-destructive mt-0.5 size-3.5 shrink-0" aria-hidden />
                <span>
                  {issue.nodeId && <span className="font-medium">{labelFor(issue.nodeId)}: </span>}
                  {issue.message}
                </span>
              </button>
            </li>
          ))}
        </ul>
      </PopoverContent>
    </Popover>
  );
}
