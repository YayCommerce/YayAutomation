import type { BlockId, WorkflowNodeData, WorkflowNodeKind } from '@yay-automation/types';

import type { Block } from './block';
import { orderStatusChangedBlock } from './orderStatusChanged.block';
import { orderTotalBlock } from './orderTotal.block';
import { sendAdminEmailBlock } from './sendAdminEmail.block';

export type { Block } from './block';

type AddableKind = Exclude<WorkflowNodeKind, 'trigger'>;

export const blocks: Record<BlockId, Block> = {
  order_status_changed: orderStatusChangedBlock,
  order_total: orderTotalBlock,
  send_admin_email: sendAdminEmailBlock,
};

/** Block for a step added with "+". One per kind for now, so no picker. Triggers only come from templates. */
const defaultBlock: Record<AddableKind, BlockId> = {
  condition: 'order_total',
  action: 'send_admin_email',
};

/** Data for a freshly added step of the given kind. */
export function newNodeData(kind: AddableKind): WorkflowNodeData {
  const block = blocks[defaultBlock[kind]];
  return { title: block.label, block: block.id, config: block.defaults() };
}
