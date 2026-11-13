import type { StepId, WorkflowGraph } from '@yaymatik/types';

import type { DataItemMeta } from '@/types/localize';
import { alwaysAvailable, dataItems, pathLabel } from './data';

/** One merge tag a step can insert. */
export interface Variable {
  /** Full path, e.g. `order.customer.first_name`, `steps.<id>.code`. */
  key: string;
  label: string;
  /** As a data field's `valueType`, e.g. `money`, `list<string>`. */
  type: string;
  /** A ready-made email block (e.g. a coupon box): inserted on its own line, in rich text only. */
  block: boolean;
}

/**
 * Variables one step can use, in the order "Insert variable" lists them: the trigger's data items and
 * the always-available ones, each item's fields then those of its relations (`order.customer.first_name`),
 * then the outputs of the steps above it, top to bottom (`steps.<id>.<key>`). Without a trigger only
 * the always-available items. Mirrors PHP `Validator::variables()`; shared cases in
 * tests/fixtures/variables.json.
 */
export function availableVariables(graph: WorkflowGraph, nodeId: string): Variable[] {
  const block = (id: string) => graph.nodes.find((node) => node.id === id)?.data.block ?? null;
  const trigger = graph.nodes.find((node) => node.kind === 'trigger');
  const triggerBlock = trigger?.data.block;
  const above = ancestors(graph, nodeId).reverse();

  const items = [
    ...new Set([
      ...(triggerBlock ? (window.yayMatikMeta.automation.steps[triggerBlock]?.provides ?? []) : []),
      ...alwaysAvailable,
      ...(triggerBlock ? above.flatMap((id) => providedByAction(block(id))) : []),
    ]),
  ];
  const facts = (triggerBlock ? (window.yayMatikMeta.automation.steps[triggerBlock]?.facts ?? []) : []).map((fact) => ({
    key: fact.key,
    label: fact.label,
    type: fact.valueType,
    block: false,
  }));
  const list = [
    ...facts,
    ...items.flatMap((id) => {
      const item = dataItems.find((i) => i.id === id);
      return item ? itemVariables(item, id, new Set([id])) : [];
    }),
  ];

  for (const id of above) {
    const stepId = block(id);
    const outputs = stepId ? (window.yayMatikMeta.automation.steps[stepId]?.outputs ?? []) : [];
    for (const output of outputs) {
      list.push({
        key: `steps.${id}.${output.key}`,
        label: output.label,
        type: output.type,
        block: !!output.block,
      });
    }
  }
  return list;
}

/** Fields a rule can compare but a merge tag can't read (custom fields), of the items in `variables`. */
export const ruleOnlyVariables = (variables: Variable[]): Variable[] =>
  dataItems.flatMap((item) =>
    variables.some((variable) => variable.key.startsWith(`${item.id}.`))
      ? item.fields
          .filter((field) => field.mergeTag === false)
          .map((field) => ({ key: field.key, label: field.label, type: field.valueType, block: false }))
      : [],
  );

/** Data items an action adds for the steps below it. */
const providedByAction = (stepId: StepId | null) =>
  stepId && window.yayMatikMeta.automation.steps[stepId]?.kind === 'action'
    ? (window.yayMatikMeta.automation.steps[stepId].provides ?? [])
    : [];

/** An item's fields under a path prefix, then its relations' (a relation back to an item on the path isn't followed). */
function itemVariables(item: DataItemMeta, prefix: string, seen: Set<string>): Variable[] {
  const own = item.fields
    .filter((field) => field.mergeTag !== false)
    .map((field) => ({
      key: `${prefix}.${field.key.slice(item.id.length + 1)}`,
      label: field.label,
      type: field.valueType,
      block: !!field.block,
    }));
  const related = item.relations.flatMap((relation) => {
    const target = dataItems.find((i) => i.id === relation.item);
    if (!target || seen.has(target.id)) return [];
    const key = `${prefix}.${relation.key.slice(item.id.length + 1)}`;
    return itemVariables(target, key, new Set([...seen, target.id]));
  });
  return [...own, ...related];
}

/** Ids of the steps above a step, nearest first, up to the trigger. One parent per step, as PHP reads edges. */
export function ancestors(graph: WorkflowGraph, nodeId: string): string[] {
  const parents = new Map(graph.edges.map((edge) => [edge.target, edge.source]));
  const above: string[] = [];
  let id = nodeId;
  while (parents.has(id) && !above.includes(parents.get(id)!)) {
    id = parents.get(id)!;
    above.push(id);
  }
  return above;
}

/**
 * One row of the first "Insert variable" level: a data item, a step above and its outputs, or the
 * email blocks of the steps above (`blocks`, built by the picker).
 */
export interface VariableGroup {
  /** Data item id (`order`) or, for `source: 'step'`, the step's node id. */
  id: string;
  source: 'data' | 'step' | 'blocks';
  /** The item's own fields (`label` null), then one section per relation path (`order.customer` → "Customer"). */
  sections: { path: string; label: string | null; variables: Variable[] }[];
}

/** `availableVariables()` grouped for "Insert variable", in the same order. */
export function groupVariables(variables: Variable[]): VariableGroup[] {
  const groups = new Map<string, VariableGroup>();
  for (const variable of variables) {
    const segments = variable.key.split('.');
    const step = segments[0] === 'steps';
    const groupKey = step ? segments.slice(0, 2).join('.') : segments[0];
    let group = groups.get(groupKey);
    if (!group) {
      group = { id: step ? segments[1] : segments[0], source: step ? 'step' : 'data', sections: [] };
      groups.set(groupKey, group);
    }
    const path = segments.slice(0, -1).join('.');
    let section = group.sections.find((s) => s.path === path);
    if (!section) {
      section = { path, label: path === groupKey ? null : relationLabel(path), variables: [] };
      group.sections.push(section);
    }
    section.variables.push(variable);
  }
  return [...groups.values()];
}

/** `order.customer` → "Customer"; deeper paths join each relation's name with " › ". */
const relationLabel = (path: string) =>
  path
    .split('.')
    .slice(1)
    .map((segment, i, rest) => {
      const prefix = [path.split('.')[0], ...rest.slice(0, i + 1)].join('.');
      return pathLabel(prefix) ?? segment;
    })
    .join(' › ');
