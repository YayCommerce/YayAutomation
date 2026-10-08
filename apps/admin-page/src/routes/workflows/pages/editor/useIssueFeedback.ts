import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { WorkflowIssue } from '@yay-automation/types';

import type { IssueMode } from '../../shared/canvas/NodeCardView';
import type { Feedback } from '../../steps/core/fields/feedback';

/**
 * How the editor shows issues:
 * - quiet while a step is being built;
 * - blue "review" on steps whose panel was left with an issue (`markVisited`);
 * - red "error" everywhere once Turn on / Apply fails (`setErrorMode(true)`), until the steps are
 *   clean again (workflow-level issues like "Add an action" don't keep it).
 */
export function useIssueFeedback(issues: WorkflowIssue[], startInErrorMode: boolean) {
  const [errorMode, setErrorMode] = useState(startInErrorMode);

  const stepIssueCount = issues.filter((issue) => issue.nodeId || issue.nodeIds).length;
  const lastStepIssueCount = useRef(stepIssueCount);
  useEffect(() => {
    const cleared = stepIssueCount === 0 && lastStepIssueCount.current > 0;
    lastStepIssueCount.current = stepIssueCount;
    if (errorMode && (cleared || issues.length === 0)) setErrorMode(false);
  }, [errorMode, stepIssueCount, issues.length]);

  const [visited, setVisited] = useState<ReadonlySet<string>>(() => new Set());
  /** A step whose panel closes counts as visited only while it has an issue. */
  const markVisited = useCallback(
    (nodeId: string) => {
      if (issues.some((issue) => issue.nodeId === nodeId)) setVisited((prev) => new Set(prev).add(nodeId));
    },
    [issues],
  );

  /** How one step's panel shows its errors. */
  const feedbackFor = useCallback(
    (nodeId: string): Feedback => (errorMode ? 'error' : visited.has(nodeId) ? 'review' : 'none'),
    [errorMode, visited],
  );

  /** Header of each card showing an issue. Steps `unlinked` lists are marked only in error mode. */
  const issueModes = useMemo(
    () =>
      new Map(
        issues.flatMap((issue): [string, IssueMode][] => {
          if (issue.nodeId) {
            const mode = feedbackFor(issue.nodeId);
            return mode === 'none' ? [] : [[issue.nodeId, mode]];
          }
          return errorMode ? (issue.nodeIds ?? []).map((id): [string, IssueMode] => [id, 'error']) : [];
        }),
      ),
    [issues, errorMode, feedbackFor],
  );

  return { errorMode, setErrorMode, feedbackFor, markVisited, issueModes };
}
