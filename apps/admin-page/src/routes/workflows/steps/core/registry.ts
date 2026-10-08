import type { StepId, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';

import type { Step } from './types';

/**
 * Indexes steps by id. Throws on a duplicate id so a copy-pasted block folder fails fast.
 * `conditionStep` is the one block a Condition step uses; triggers and actions are picked.
 */
export function createRegistry(list: Step[], conditionStep: StepId) {
  const steps = {} as Record<StepId, Step>;
  for (const block of list) {
    if (steps[block.id]) throw new Error(`Duplicate block id "${block.id}"`);
    steps[block.id] = block;
  }

  /** Data for a fresh step: triggers and actions start unchosen, a condition with its defaults. */
  function newNodeData(kind: WorkflowNodeKind): WorkflowNodeData {
    if (kind !== 'condition') return { block: null, config: {} };
    return { block: conditionStep, config: steps[conditionStep].defaults() };
  }

  /** Data after choosing a block for a trigger or action step: its defaults. Re-picking the same block keeps its settings. */
  function withStep(data: WorkflowNodeData, id: StepId): WorkflowNodeData {
    return data.block === id ? data : { ...data, block: id, config: steps[id].defaults() };
  }

  /** The step's block, or undefined while it isn't chosen. */
  const stepOf = (data: WorkflowNodeData): Step | undefined => (data.block ? steps[data.block] : undefined);

  return { steps, newNodeData, withStep, stepOf };
}
