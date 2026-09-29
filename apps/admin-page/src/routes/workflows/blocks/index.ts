import { sendAdminEmailBlock } from './actions/send-admin-email';
import { orderTotalBlock } from './conditions/order-total';
import { createRegistry } from './core/registry';
import { orderStatusChangedBlock } from './triggers/order-status-changed';

export type { Block } from './core/types';

/**
 * Every block the editor knows. To add one: create its folder under triggers/, conditions/ or
 * actions/ (named after the block id in kebab-case) and list it here.
 */
export const { blocks, newNodeData } = createRegistry(
  [orderStatusChangedBlock, orderTotalBlock, sendAdminEmailBlock],
  // Block that "+" adds for each step kind. One per kind for now, so there's no picker.
  { condition: 'order_total', action: 'send_admin_email' },
);
