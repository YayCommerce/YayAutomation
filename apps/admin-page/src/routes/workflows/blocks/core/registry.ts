import type { BlockId, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';

import type { Block } from './types';

type AddableKind = Exclude<WorkflowNodeKind, 'trigger'>;

/**
 * Indexes blocks by id and picks the block "+" adds for each step kind. Triggers only come from
 * templates, so they have no default. Throws on a duplicate id so a copy-pasted block folder fails fast.
 */
export function createRegistry(list: Block[], defaultFor: Record<AddableKind, BlockId>) {
  const blocks = {} as Record<BlockId, Block>;
  for (const block of list) {
    if (blocks[block.id]) throw new Error(`Duplicate block id "${block.id}"`);
    blocks[block.id] = block;
  }

  /** Data for a freshly added step of the given kind. */
  function newNodeData(kind: AddableKind): WorkflowNodeData {
    const block = blocks[defaultFor[kind]];
    return { title: block.label, block: block.id, config: block.defaults() };
  }

  return { blocks, newNodeData };
}
