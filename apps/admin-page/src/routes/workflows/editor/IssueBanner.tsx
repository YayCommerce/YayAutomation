import { useState } from 'react';
import type { WorkflowIssue } from '@yay-automation/types';
import { ChevronLeft, ChevronRight, CircleAlert, Eye } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';

/**
 * Error-mode banner: one issue at a time, with "1 of N" paging. A step issue names the step and links
 * to it; `unlinked` links to its first step; other workflow-level issues show just the message.
 */
export function IssueBanner({
  issues,
  labelFor,
  onView,
  className,
}: {
  issues: WorkflowIssue[];
  /** Block label of a step. */
  labelFor: (nodeId: string) => string;
  onView: (nodeId: string) => void;
  className?: string;
}) {
  const [index, setIndex] = useState(0);
  if (!issues.length) return null;

  // Issues get fixed while the banner is up; stay on the last one rather than past the end.
  const current = Math.min(index, issues.length - 1);
  const issue = issues[current];
  const stepId = issue.nodeId ?? issue.nodeIds?.[0];

  return (
    <div
      role="alert"
      className={cn(
        'border-destructive/30 bg-destructive/10 text-destructive w-md max-w-[calc(100%-2rem)] rounded-lg border p-4 text-sm shadow-sm backdrop-blur-sm',
        className,
      )}
    >
      <div className="flex items-start gap-2">
        <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
        <div className="min-w-0 flex-1 space-y-2">
          {issue.nodeId ? (
            <>
              <p>{__('Correct the error for this workflow step:', 'yay-automation')}</p>
              <ul className="list-disc pl-5">
                <li>{issue.message}</li>
              </ul>
            </>
          ) : (
            <p>{issue.message}</p>
          )}

          {stepId && (
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
          )}

          {issues.length > 1 && (
            <div className="flex items-center gap-1 pt-1">
              <Button
                variant="ghost"
                size="icon-sm"
                className="text-destructive size-6"
                disabled={current === 0}
                aria-label={__('Previous error', 'yay-automation')}
                onClick={() => setIndex(current - 1)}
              >
                <ChevronLeft />
              </Button>
              <Button
                variant="ghost"
                size="icon-sm"
                className="text-destructive size-6"
                disabled={current === issues.length - 1}
                aria-label={__('Next error', 'yay-automation')}
                onClick={() => setIndex(current + 1)}
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
        </div>
      </div>
    </div>
  );
}
