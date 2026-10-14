import type { Workflow, WorkflowGraph, WorkflowIssue, WorkflowNode } from '@yay-automation/types';
import { sprintf } from '@wordpress/i18n';

import { stepOf } from '../steps';
import { alwaysAvailable, itemLabel } from '../steps/core/data';
import { descendantsOf } from './graph';
import { messages } from './messages';

/**
 * Everything that blocks turning a workflow on or applying its draft (saves allow them). Same rules
 * and codes as PHP `Validator::errors()`, which rejects activate / apply with a 400.
 */
export function validateWorkflow({ name, graph }: Pick<Workflow, 'name' | 'graph'>): WorkflowIssue[] {
  const issues: WorkflowIssue[] = [];

  // The server runs sanitize_text_field(), which drops tags, so `<b></b>` counts as empty there too.
  if (!name.replace(/<[^>]*>/g, '').trim()) {
    issues.push({ code: 'name_required', message: messages.nameRequired });
  }
  const trigger = graph.nodes.find((node) => node.kind === 'trigger');
  const triggerStep = trigger && stepOf(trigger.data);
  // Workflow-level: there is no step to point at.
  if (!triggerStep) {
    issues.push({ code: 'no_trigger', message: messages.noTrigger });
  }
  if (!graph.nodes.some((node) => node.kind === 'action')) {
    issues.push({ code: 'no_action', message: messages.noAction });
  }

  // Steps left behind by a delete. One workflow-level issue for all of them; they only get their
  // own (red header) issues for their own settings.
  const reachable = trigger ? descendantsOf(graph, trigger.id).add(trigger.id) : new Set<string>();
  const unlinked = graph.nodes.filter((node) => !reachable.has(node.id)).map((node) => node.id);
  if (unlinked.length) {
    issues.push({
      code: 'unlinked',
      nodeIds: unlinked,
      message: messages.unlinked,
    });
  }

  // Data each step can read. Unknown until a trigger is chosen; no_trigger covers that case.
  const availableAt = availableData(graph);
  for (const node of graph.nodes) {
    const issue = stepIssue(node, availableAt?.(node.id) ?? null);
    if (issue) issues.push({ ...issue, nodeId: node.id });
  }
  return issues;
}

/**
 * Issues that stop an inactive workflow from being activated: those of the content Activate would
 * make live, its draft when it has one (the server checks the same). None for an active workflow.
 */
export const activateIssues = (workflow: Pick<Workflow, 'name' | 'status' | 'draft' | 'graph'>) =>
  workflow.status === 'active'
    ? []
    : validateWorkflow({ name: workflow.name, graph: workflow.draft ?? workflow.graph });

/**
 * Data items each step can read: what the trigger supplies, what the actions above it add (e.g. a
 * coupon), and the always-available ones. Steps not linked to the trigger get the trigger's data.
 * Null until a trigger is chosen. PHP `Validator` also checks `steps.<id>.*` merge tags against the
 * steps above; the editor doesn't parse those tags yet. Only coming-soon actions provide data items
 * so far (see `pending.ts`).
 */
export function availableData(graph: WorkflowGraph): ((nodeId: string) => string[]) | null {
  const trigger = graph.nodes.find((node) => node.kind === 'trigger');
  const triggerStep = trigger && stepOf(trigger.data);
  if (!trigger || !triggerStep) return null;

  const base = [...new Set([...triggerStep.provides, ...alwaysAvailable])];
  const nodes = new Map(graph.nodes.map((node) => [node.id, node]));
  const children = new Map<string, string[]>();
  for (const { source, target } of graph.edges) children.set(source, [...(children.get(source) ?? []), target]);
  const byNode = new Map([[trigger.id, base]]);
  const queue = [trigger.id];
  while (queue.length) {
    const id = queue.shift()!;
    const node = nodes.get(id)!;
    const added = node.kind === 'action' ? (stepOf(node.data)?.provides ?? []) : [];
    const below = added.length ? [...new Set([...byNode.get(id)!, ...added])] : byNode.get(id)!;
    for (const target of children.get(id) ?? []) {
      if (!byNode.has(target) && nodes.has(target)) {
        byNode.set(target, below);
        queue.push(target);
      }
    }
  }
  return (nodeId) => byNode.get(nodeId) ?? base;
}

/** Same items in the same order. For `useStore`, which would otherwise re-render on every new array. */
export const sameItems = (a: string[], b: string[]) => a.length === b.length && a.every((item, i) => item === b[i]);

/** The one issue of a step's own settings. Mirrors PHP `Validator::step_error()`. */
function stepIssue(node: WorkflowNode, available: string[] | null): Omit<WorkflowIssue, 'nodeId'> | null {
  const stepDef = stepOf(node.data);
  if (!stepDef) {
    return node.kind === 'action' ? { code: 'no_block', message: messages.noBlock } : null;
  }

  const error = stepDef.firstError(node.data.config);
  if (error) return { code: 'invalid_config', message: error };

  const missing = available && stepDef.uses(node.data.config).find((item) => !available.includes(item));
  if (missing) {
    return {
      code: 'missing_data',
      message: sprintf(messages.missingData, itemLabel(missing)),
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
