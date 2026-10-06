import type { Workflow, WorkflowGraph, WorkflowIssue, WorkflowNode } from '@yay-automation/types';
import { __, sprintf } from '@wordpress/i18n';

import { blockOf } from '../blocks';
import { alwaysAvailable, itemLabel } from '../blocks/core/data';
import { descendantsOf } from './graph';

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
  const triggerBlock = trigger && blockOf(trigger.data);
  // Workflow-level: no step to point at, like Shopify Flow.
  if (!triggerBlock) {
    issues.push({ code: 'no_trigger', message: __('Add a trigger to this workflow', 'yay-automation') });
  }
  if (!graph.nodes.some((node) => node.kind === 'action')) {
    issues.push({ code: 'no_action', message: __('Add an action to this workflow', 'yay-automation') });
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

  // Data the steps can read. Unknown until a trigger is chosen; no_trigger covers that case.
  const available = triggerBlock ? availableData(triggerBlock.provides) : null;
  for (const node of graph.nodes) {
    const issue = stepIssue(node, available);
    if (issue) issues.push({ ...issue, nodeId: node.id });
  }
  return issues;
}

/** Data items a step can read when the trigger supplies `provides`. */
export const availableData = (provides: string[]) => [...provides, ...alwaysAvailable];

/** The one issue of a step's own settings. Mirrors PHP `WorkflowValidator::step_error()`. */
function stepIssue(node: WorkflowNode, available: string[] | null): Omit<WorkflowIssue, 'nodeId'> | null {
  const block = blockOf(node.data);
  if (!block) {
    return node.kind === 'action' ? { code: 'no_block', message: __('Select an action', 'yay-automation') } : null;
  }

  const error = block.firstError(node.data.config);
  if (error) return { code: 'invalid_config', message: error };

  const missing = available && block.uses(node.data.config).find((item) => !available.includes(item));
  if (missing) {
    return {
      code: 'missing_data',
      /* translators: %s: data item, e.g. "Order" */
      message: sprintf(__("Uses %s data, which the trigger doesn't provide.", 'yay-automation'), itemLabel(missing)),
    };
  }
  return null;
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
