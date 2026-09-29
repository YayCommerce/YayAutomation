import type { BlockId, WorkflowGraph, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';

import type { Block } from './block';
import { orderStatusChangedBlock } from './orderStatusChanged.block';
import { orderTotalBlock } from './orderTotal.block';
import { sendAdminEmailBlock } from './sendAdminEmail.block';

export type { Block } from './block';

export const blocks: Record<BlockId, Block> = {
  order_status_changed: orderStatusChangedBlock,
  order_total: orderTotalBlock,
  send_admin_email: sendAdminEmailBlock,
};

/** One block per kind for now, so adding a step needs no picker. */
const defaultBlock: Record<WorkflowNodeKind, BlockId> = {
  trigger: 'order_status_changed',
  condition: 'order_total',
  action: 'send_admin_email',
};

/** Data for a freshly added step of the given kind. */
export function newNodeData(kind: WorkflowNodeKind): WorkflowNodeData {
  const block = blocks[defaultBlock[kind]];
  return { title: block.label, block: block.id, config: block.defaults() };
}

export function isConfigured(data: WorkflowNodeData) {
  return blocks[data.block].isConfigured(data.config);
}

/** A workflow can be active only when this is true. The PHP validator enforces the same rule. */
export function allConfigured(graph: WorkflowGraph) {
  return graph.nodes.every((node) => isConfigured(node.data));
}
