import { addOrderNoteBlock } from './actions/add-order-note';
import { changeOrderStatusBlock } from './actions/change-order-status';
import { generateCouponBlock } from './actions/generate-coupon';
import { sendEmailBlock } from './actions/send-email';
import { sendInternalEmailBlock } from './actions/send-internal-email';
import { updateOrderMetaBlock } from './actions/update-order-meta';
import { criteriaBlock } from './conditions/criteria';
import { createRegistry } from './core/registry';
import { orderCreatedBlock } from './triggers/order-created';
import { orderStatusChangedBlock } from './triggers/order-status-changed';

export type { Block } from './core/types';
export { SummaryText } from './core/defineBlock';

/**
 * Every block the editor knows. To add one: create its folder under triggers/, conditions/ or
 * actions/ (named after the block id in kebab-case), add its PHP class, and list it here.
 */
export const { blocks, newNodeData, withBlock, blockOf } = createRegistry(
  [
    orderCreatedBlock,
    orderStatusChangedBlock,
    criteriaBlock,
    sendEmailBlock,
    sendInternalEmailBlock,
    changeOrderStatusBlock,
    addOrderNoteBlock,
    updateOrderMetaBlock,
    generateCouponBlock,
  ],
  'condition',
);
