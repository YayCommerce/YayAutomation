import type { Workflow, WorkflowGraph, WorkflowIssue, WorkflowNode } from '@yaymatik/types';
import { __, sprintf } from '@wordpress/i18n';

import { stepOf } from '../steps';
import { alwaysAvailable, isStepPath, itemLabel, pathSegments, variableTag } from '../steps/core/data';
import { ancestors } from '../steps/core/variables';
import { descendantsOf } from './graph';
import { messages } from './messages';

/**
 * Everything that blocks Activate or Update (saves allow them). Same rules and codes as PHP
 * `Validator::errors()`, which rejects activate / apply with a 400.
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
    const issue = stepIssue(node, availableAt?.(node.id) ?? null, graph, new Set(ancestors(graph, node.id)));
    if (issue) issues.push({ ...issue, nodeId: node.id });
  }
  return issues;
}

/**
 * Steps (other than `changed`) with an issue after an edit that wasn't in `before` (the issues
 * before it): e.g. the steps that used the outputs of a step whose step was swapped.
 */
export function newStepIssues(
  before: WorkflowIssue[],
  after: Pick<Workflow, 'name' | 'graph'>,
  changed: string,
): string[] {
  const key = (issue: WorkflowIssue) => `${issue.code}|${issue.nodeId ?? ''}|${issue.message}`;
  const had = new Set(before.map(key));
  return validateWorkflow(after).flatMap((issue) =>
    issue.nodeId && issue.nodeId !== changed && !had.has(key(issue)) ? [issue.nodeId] : [],
  );
}

/**
 * Issues that stop an inactive workflow from being activated, as the server found them: its saved
 * content, then its draft if it has one (each issue once). None for an active workflow.
 */
export function activateIssues(workflow: Pick<Workflow, 'status' | 'issues'>): WorkflowIssue[] {
  if (workflow.status === 'active') return [];
  const all = [...workflow.issues.live, ...(workflow.issues.draft ?? [])];
  const key = (issue: WorkflowIssue) => `${issue.code}|${issue.nodeId ?? ''}|${issue.message}`;
  return all.filter((issue, i) => all.findIndex((other) => key(other) === key(issue)) === i);
}

/**
 * An issue's message, after its step's name when it's about a step and doesn't name it:
 * "Change order status: Choose an option from the list." The step is looked up in `graphs`, in
 * order (the graph the issue came from first).
 */
export function issueText(issue: WorkflowIssue, ...graphs: (WorkflowGraph | null | undefined)[]): string {
  if (!issue.nodeId) return issue.message;
  const node = graphs.flatMap((graph) => graph?.nodes ?? []).find((n) => n.id === issue.nodeId);
  const label = node && stepOf(node.data)?.label;
  if (!label || issue.message.includes(label)) return issue.message;
  /* translators: 1: a step, e.g. "Change order status", 2: what's wrong with it */
  return sprintf(__('%1$s: %2$s', 'yaymatik'), label, issue.message);
}

/** `activateIssues()` as text, each line once. */
export function activateIssueTexts(workflow: Pick<Workflow, 'status' | 'issues' | 'graph' | 'draft'>): string[] {
  if (workflow.status === 'active') return [];
  return [
    ...new Set([
      ...workflow.issues.live.map((issue) => issueText(issue, workflow.graph)),
      ...(workflow.issues.draft ?? []).map((issue) => issueText(issue, workflow.draft)),
    ]),
  ];
}

/** Activate is offered only when the server would accept it. */
export const canActivate = (workflow: Pick<Workflow, 'status' | 'issues'>) =>
  workflow.status !== 'active' && activateIssues(workflow).length === 0;

/** Steps with an issue of their own (`nodeId`), each counted once. Unconnected steps aren't counted: their issue says so. */
export const stepsNeedingSetup = (issues: WorkflowIssue[]) =>
  new Set(issues.flatMap((issue) => (issue.nodeId ? [issue.nodeId] : []))).size;

/**
 * Data items each step can read: what the trigger supplies, what the actions above it add (e.g. a
 * coupon), and the always-available ones. Steps not linked to the trigger get the trigger's data.
 * Null until a trigger is chosen.
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

/**
 * The one issue of a step's own settings. Mirrors PHP `Validator::step_error()`: its settings and
 * merge-tag syntax (`firstIssue`), then its tags' paths (a step output not above it, data the trigger
 * doesn't supply), then the data it needs.
 */
function stepIssue(
  node: WorkflowNode,
  available: string[] | null,
  graph: WorkflowGraph,
  above: Set<string>,
): Omit<WorkflowIssue, 'nodeId'> | null {
  const stepDef = stepOf(node.data);
  if (!stepDef) {
    return node.kind === 'action' ? { code: 'no_block', message: messages.noBlock } : null;
  }

  const error = stepDef.firstIssue(node.data.config);
  if (error) return { code: 'invalid_config', ...error };

  // Each names the field that holds the tag, like PHP.
  let missing: Omit<WorkflowIssue, 'nodeId'> | null = null;
  for (const { field, path } of stepDef.tags(node.data.config)) {
    const problem = reachProblem(path, available, graph, above);
    if (problem?.code === 'invalid_config') return { ...problem, field };
    if (problem) missing ??= { ...problem, field };
  }
  if (missing) return missing;

  const missingItem = available && stepDef.uses(node.data.config).find((item) => !available.includes(item));
  if (missingItem) {
    return {
      code: 'missing_data',
      message: sprintf(messages.missingData, itemLabel(missingItem)),
    };
  }
  return null;
}

/**
 * Why a step can't read a tag's path, or null when it can: `invalid_config` when nothing defines it
 * (or the step it reads isn't in the workflow), `missing_data` when the data or the step it reads
 * isn't above it. Data paths were checked by the step's schema. Mirrors PHP `Validator::reach()`.
 */
function reachProblem(
  path: string,
  available: string[] | null,
  graph: WorkflowGraph,
  above: Set<string>,
): Omit<WorkflowIssue, 'nodeId'> | null {
  const tag = variableTag(path);
  const segments = pathSegments(path);
  if (isStepPath(segments)) {
    const node = graph.nodes.find((n) => n.id === segments[1]);
    if (!node) return { code: 'invalid_config', message: sprintf(messages.stepGone, tag) };
    const stepDef = stepOf(node.data);
    if (!stepDef?.outputs.some((output) => output.key === segments[2])) {
      return { code: 'invalid_config', message: sprintf(messages.unknownVariable, tag) };
    }
    return above.has(node.id)
      ? null
      : { code: 'missing_data', message: sprintf(messages.stepNotAbove, tag, stepDef.label) };
  }
  if (available === null || available.includes(segments[0])) return null;
  return { code: 'missing_data', message: sprintf(messages.missingData, itemLabel(segments[0])) };
}

/** Issues the server found in a graph the editor sent (or opened with), and that graph. */
export interface ServerCheck {
  graph: WorkflowGraph;
  issues: WorkflowIssue[];
}

/** The server's issues for the graph the editor edits: the draft's while there is one, else the live graph's. */
export const editedIssues = (workflow: Pick<Workflow, 'draft' | 'issues'>) =>
  workflow.draft !== null ? (workflow.issues.draft ?? []) : workflow.issues.live;

/**
 * The editor's issues plus the step settings issues only PHP finds (`Step::validate()` rules such as
 * a percentage over 100), from the server's last check. A server issue counts while its step's data
 * is the same object as in the graph that was checked and the editor has no issue of its own for it.
 */
export function withServerIssues(issues: WorkflowIssue[], server: ServerCheck, graph: WorkflowGraph): WorkflowIssue[] {
  const checked = new Map(server.graph.nodes.map((node) => [node.id, node.data]));
  const own = new Set(issues.map((issue) => issue.nodeId));
  const extra = server.issues.filter(
    ({ code, nodeId }) =>
      code === 'invalid_config' &&
      !!nodeId &&
      !own.has(nodeId) &&
      graph.nodes.some((node) => node.id === nodeId && node.data === checked.get(nodeId)),
  );
  return extra.length ? [...issues, ...extra] : issues;
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
