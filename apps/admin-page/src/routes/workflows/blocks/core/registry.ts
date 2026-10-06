import type { BlockId, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';

import type { Block } from './types';

/**
 * Indexes blocks by id. Throws on a duplicate id so a copy-pasted block folder fails fast.
 * `conditionBlock` is the one block a Condition step uses; triggers and actions are picked.
 */
export function createRegistry(list: Block[], conditionBlock: BlockId) {
  const blocks = {} as Record<BlockId, Block>;
  for (const block of list) {
    if (blocks[block.id]) throw new Error(`Duplicate block id "${block.id}"`);
    blocks[block.id] = block;
  }

  /** Data for a fresh step: triggers and actions start unchosen, a condition with its defaults. */
  function newNodeData(kind: WorkflowNodeKind): WorkflowNodeData {
    if (kind !== 'condition') return { block: null, config: {} };
    return { block: conditionBlock, config: blocks[conditionBlock].defaults() };
  }

  /** Data after choosing a block for a trigger or action step: its defaults. Re-picking the same block keeps its settings. */
  function withBlock(data: WorkflowNodeData, id: BlockId): WorkflowNodeData {
    return data.block === id ? data : { ...data, block: id, config: blocks[id].defaults() };
  }

  /** The step's block, or undefined while it isn't chosen. */
  const blockOf = (data: WorkflowNodeData): Block | undefined => (data.block ? blocks[data.block] : undefined);

  return { blocks, newNodeData, withBlock, blockOf };
}
