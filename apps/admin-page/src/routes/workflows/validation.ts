import type { Workflow, WorkflowGraph, WorkflowIssue } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

import { blocks } from './blocks';
import { descendantsOf } from './editor/workflow.graph';

/**
 * Everything that blocks turning a workflow on or applying its draft (saves allow them). Same rules
 * and codes as PHP `WorkflowValidator::errors()`, which rejects activate / apply with a 400.
 */
export function validateWorkflow({ name, graph }: Pick<Workflow, 'name' | 'graph'>): WorkflowIssue[] {
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

  return (values: Pick<Workflow, 'name' | 'graph'>) => {
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
