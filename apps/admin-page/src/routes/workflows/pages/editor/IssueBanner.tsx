import { useState } from 'react';
import type { WorkflowIssue } from '@yay-automation/types';
import { ChevronLeft, ChevronRight, CircleAlert, Eye, Pencil } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ErrorAlert } from '../../shared/ui/ErrorAlert';

const issueKey = (issue: WorkflowIssue) => `${issue.code}|${issue.nodeId ?? ''}`;

/**
 * Error-mode banner: one issue at a time, with "1 of N" paging. A step issue names the step and links
 * to it; `unlinked` links to its first step; `name_required` offers Rename.
 */
export function IssueBanner({
  issues,
  labelFor,
  onView,
  onRename,
  className,
}: {
  issues: WorkflowIssue[];
  /** Block label of a step. */
  labelFor: (nodeId: string) => string;
  onView: (nodeId: string) => void;
  onRename: () => void;
  className?: string;
}) {
  // Follows the issue being read, not a position: fixing an earlier one mustn't swap the message.
  const [shown, setShown] = useState<{ key: string; index: number } | null>(null);
  if (!issues.length) return null;

  const found = shown ? issues.findIndex((issue) => issueKey(issue) === shown.key) : -1;
  // Gone (fixed) → the one now in its place, or the last.
  const current = found >= 0 ? found : Math.min(shown?.index ?? 0, issues.length - 1);
  const issue = issues[current];
  const stepId = issue.nodeId ?? issue.nodeIds?.[0];
  const show = (index: number) => setShown({ key: issueKey(issues[index]), index });

  return (
    <ErrorAlert className={cn('w-md max-w-[calc(100%-2rem)] shadow-sm', className)}>
      <CircleAlert />
      <AlertTitle className="line-clamp-none font-normal">
        {issue.nodeId ? __('Correct the error for this workflow step:', 'yay-automation') : issue.message}
      </AlertTitle>
      <AlertDescription className="gap-2">
        {issue.nodeId && (
          <ul className="list-disc pl-5">
            <li>{issue.message}</li>
          </ul>
        )}

        {stepId ? (
          <Button
            variant="link"
            size="sm"
            className="text-destructive h-auto p-0 font-normal"
            onClick={() => onView(stepId)}
          >
            <Eye />
            {sprintf(
              /* translators: %s: step name, e.g. "Order total" */
              __('View ‘%s’ step', 'yay-automation'),
              labelFor(stepId),
            )}
          </Button>
        ) : (
          issue.code === 'name_required' && (
            <Button variant="link" size="sm" className="text-destructive h-auto p-0 font-normal" onClick={onRename}>
              <Pencil />
              {__('Rename workflow', 'yay-automation')}
            </Button>
          )
        )}

        {issues.length > 1 && (
          <div className="flex items-center gap-1 pt-1">
            <Button
              variant="ghost"
              size="icon-sm"
              className="text-destructive size-6"
              disabled={current === 0}
              aria-label={__('Previous error', 'yay-automation')}
              onClick={() => show(current - 1)}
            >
              <ChevronLeft />
            </Button>
            <Button
              variant="ghost"
              size="icon-sm"
              className="text-destructive size-6"
              disabled={current === issues.length - 1}
              aria-label={__('Next error', 'yay-automation')}
              onClick={() => show(current + 1)}
            >
              <ChevronRight />
            </Button>
            <span className="ml-1 text-xs">
              {sprintf(
                /* translators: 1: current error number, 2: number of errors */
                __('%1$d of %2$d', 'yay-automation'),
                current + 1,
                issues.length,
              )}
            </span>
          </div>
        )}
      </AlertDescription>
    </ErrorAlert>
  );
}
