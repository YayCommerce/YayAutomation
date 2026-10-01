import type { WorkflowGraph } from '@yay-automation/types';

import { blocks } from '../blocks';

const ID = /^[A-Za-z0-9_-]{1,200}$/;
const BRANCHES = ['then', 'otherwise'];

/**
 * Why the server would reject this graph's shape on save, one message per problem (empty = fine).
 * Same rules as PHP `WorkflowValidator::prepare_graph()`: valid unique step ids, known blocks of the
 * right kind, exactly one trigger, and edges that form a tree (only conditions branch, and always
 * name the branch; one parent per step; one child per handle).
 *
 * The editor can't produce a bad shape, so this guards hand-written graphs (templates). Run it before
 * `validateWorkflow()`, which assumes every block exists.
 */
export function structureErrors(graph: WorkflowGraph): string[] {
  const errors: string[] = [];
  const kinds = new Map<string, string>();
  let triggers = 0;

  for (const node of graph.nodes) {
    if (!ID.test(node.id) || kinds.has(node.id)) errors.push(`Invalid or duplicate step id "${node.id}"`);
    const block = blocks[node.data.block] as (typeof blocks)[keyof typeof blocks] | undefined;
    if (!block) errors.push(`Step "${node.id}": unknown block "${node.data.block}"`);
    else if (block.kind !== node.kind) errors.push(`Step "${node.id}": block "${block.id}" isn't a ${node.kind}`);
    if (node.kind === 'trigger') triggers++;
    kinds.set(node.id, node.kind);
  }
  if (triggers !== 1) errors.push(`Needs exactly one trigger (found ${triggers})`);

  const edgeIds = new Set<string>();
  const hasParent = new Set<string>();
  const usedSlots = new Set<string>();
  for (const edge of graph.edges) {
    const source = kinds.get(edge.source);
    const target = kinds.get(edge.target);
    const handle = edge.sourceHandle ?? null;
    const slot = `${edge.source}|${handle}`;
    const valid =
      ID.test(edge.id) &&
      !edgeIds.has(edge.id) &&
      source !== undefined &&
      target !== undefined &&
      edge.source !== edge.target &&
      target !== 'trigger' &&
      (source === 'condition' ? handle !== null && BRANCHES.includes(handle) : handle === null) &&
      !hasParent.has(edge.target) &&
      !usedSlots.has(slot);
    if (!valid) errors.push(`Invalid connection "${edge.id}" (${edge.source} → ${edge.target})`);
    edgeIds.add(edge.id);
    hasParent.add(edge.target);
    usedSlots.add(slot);
  }
  return errors;
}
