import type { WorkflowDraft, WorkflowGraph, WorkflowIssue } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

import { blocks } from './blocks';
import { descendantsOf } from './editor/workflow.graph';

/**
 * Everything that blocks saving. Same rules and codes as PHP `WorkflowValidator::errors()`, which
 * rejects the save with a 400 if any slip through.
 */
export function validateWorkflow({ name, graph }: Pick<WorkflowDraft, 'name' | 'graph'>): WorkflowIssue[] {
  const issues: WorkflowIssue[] = [];

  // The server runs sanitize_text_field(), which drops tags, so `<b></b>` counts as empty there too.
  if (!name.replace(/<[^>]*>/g, '').trim()) {
    issues.push({ code: 'name_required', message: __('Enter a workflow name.', 'yay-automation') });
  }
  const trigger = graph.nodes.find((node) => node.kind === 'trigger');
  if (!graph.nodes.some((node) => node.kind === 'action')) {
    // Shown on the trigger: that's where the next step gets added.
    issues.push({
      code: 'no_action',
      nodeId: trigger?.id,
      message: __('Add an action to this workflow', 'yay-automation'),
    });
  }

  // Steps left behind by a delete. One workflow-level issue for all of them; they only get their
  // own (red header) issues for their own settings.
  const reachable = trigger ? descendantsOf(graph, trigger.id).add(trigger.id) : new Set<string>();
  const unlinked = graph.nodes.filter((node) => !reachable.has(node.id)).map((node) => node.id);
  if (unlinked.length) {
    issues.push({
      code: 'unlinked',
      nodeIds: unlinked,
      message: __('Remove or connect unlinked steps', 'yay-automation'),
    });
  }

  for (const node of graph.nodes) {
    const error = blocks[node.data.block].firstError(node.data.config);
    if (error) {
      issues.push({ code: 'invalid_config', nodeId: node.id, message: error });
    }
  }
  return issues;
}

/** For `useStore`: a fresh array each run, so compare contents to avoid re-rendering on every edit. */
export function sameIssues(a: WorkflowIssue[], b: WorkflowIssue[]) {
  return (
    a.length === b.length &&
    a.every(
      (issue, i) =>
        issue.code === b[i].code &&
        issue.nodeId === b[i].nodeId &&
        issue.message === b[i].message &&
        issue.nodeIds?.join() === b[i].nodeIds?.join(),
    )
  );
}

/**
 * `validateWorkflow` for a live form store. Dragging only changes node positions, which can't change
 * the issues, so the last result is reused unless the name, edges or any node's data changed.
 */
export function createIssuesSelector() {
  let last: { name: string; edges: WorkflowGraph['edges']; data: unknown[]; issues: WorkflowIssue[] } | null = null;

  return (values: Pick<WorkflowDraft, 'name' | 'graph'>) => {
    const data = values.graph.nodes.map((node) => node.data);
    if (
      last &&
      last.name === values.name &&
      last.edges === values.graph.edges &&
      last.data.length === data.length &&
      last.data.every((d, i) => d === data[i])
    ) {
      return last.issues;
    }
    const issues = validateWorkflow(values);
    last = { name: values.name, edges: values.graph.edges, data, issues };
    return issues;
  };
}

/**
 * True when the only change from the saved workflow is turning it off. Such a save is allowed even
 * with issues, so a workflow that became invalid can always be stopped. Same rule as PHP
 * `WorkflowValidator::only_deactivates()`.
 */
export function onlyDeactivates(values: WorkflowDraft, saved: WorkflowDraft) {
  return (
    !!saved.id &&
    saved.status === 'active' &&
    values.status === 'inactive' &&
    values.name === saved.name &&
    sameValue(values.graph, saved.graph)
  );
}

/** Deep equality for JSON-like data; key order doesn't matter (config objects get rebuilt by forms). */
function sameValue(a: unknown, b: unknown): boolean {
  if (a === b) return true;
  if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null) return false;
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  const aKeys = Object.keys(a).filter((key) => (a as Record<string, unknown>)[key] !== undefined);
  const bKeys = Object.keys(b).filter((key) => (b as Record<string, unknown>)[key] !== undefined);
  return (
    aKeys.length === bKeys.length &&
    aKeys.every((key) => sameValue((a as Record<string, unknown>)[key], (b as Record<string, unknown>)[key]))
  );
}
