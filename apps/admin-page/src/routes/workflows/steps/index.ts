import type { StepId } from '@yaymatik/types';

import { addOrderNoteStep } from './actions/add-order-note';
import { changeOrderStatusStep } from './actions/change-order-status';
import { generateCouponStep } from './actions/generate-coupon';
import { sendEmailStep } from './actions/send-email';
import { sendInternalEmailStep } from './actions/send-internal-email';
import { updateOrderMetaStep } from './actions/update-order-meta';
import { waitStep } from './actions/wait';
import { criteriaStep } from './conditions/criteria';
import { defineStep } from './core/defineStep';
import { createRegistry } from './core/registry';
import { cartAbandonedStep } from './triggers/cart-abandoned';
import { checkoutAbandonedStep } from './triggers/checkout-abandoned';
import { orderCreatedStep } from './triggers/order-created';
import { orderStatusChangedStep } from './triggers/order-status-changed';

export type { Step } from './core/types';
export { plainSummary, SummaryText } from './core/defineStep';

const coreSteps = [
  orderCreatedStep,
  orderStatusChangedStep,
  cartAbandonedStep,
  checkoutAbandonedStep,
  criteriaStep,
  sendEmailStep,
  sendInternalEmailStep,
  changeOrderStatusStep,
  addOrderNoteStep,
  updateOrderMetaStep,
  generateCouponStep,
  waitStep,
];

/** Another plugin's triggers and actions: PHP only, so their form and checks come from their catalog fields. */
const coreIds = new Set<string>(coreSteps.map((stepDef) => stepDef.id));
const moduleSteps = Object.entries(window.yayMatikMeta.automation.steps)
  .filter(([id, meta]) => !coreIds.has(id) && (meta?.kind === 'trigger' || meta?.kind === 'action'))
  .map(([id, meta]) => defineStep({ id: id as StepId, kind: meta!.kind }));

/**
 * Every step the editor knows. To add a core one: create its folder under triggers/, conditions/ or
 * actions/ (named after the step id in kebab-case), add its PHP class, and list it here.
 */
export const { steps, newNodeData, withStep, stepOf } = createRegistry([...coreSteps, ...moduleSteps], 'condition');
