import { addOrderNoteStep } from './actions/add-order-note';
import { changeOrderStatusStep } from './actions/change-order-status';
import { generateCouponStep } from './actions/generate-coupon';
import { sendEmailStep } from './actions/send-email';
import { sendInternalEmailStep } from './actions/send-internal-email';
import { updateOrderMetaStep } from './actions/update-order-meta';
import { waitStep } from './actions/wait';
import { criteriaStep } from './conditions/criteria';
import { createRegistry } from './core/registry';
import { orderCreatedStep } from './triggers/order-created';
import { orderStatusChangedStep } from './triggers/order-status-changed';

export type { Step } from './core/types';
export { plainSummary, SummaryText } from './core/defineStep';

/**
 * Every step the editor knows. To add one: create its folder under triggers/, conditions/ or
 * actions/ (named after the step id in kebab-case), add its PHP class, and list it here.
 */
export const { steps, newNodeData, withStep, stepOf } = createRegistry(
  [
    orderCreatedStep,
    orderStatusChangedStep,
    criteriaStep,
    sendEmailStep,
    sendInternalEmailStep,
    changeOrderStatusStep,
    addOrderNoteStep,
    updateOrderMetaStep,
    generateCouponStep,
    waitStep,
  ],
  'condition',
);
