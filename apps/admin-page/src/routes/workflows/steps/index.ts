import { steps as editionSteps } from '@edition/steps';
import type { StepId } from '@yaymatik/types';

import { addOrderNoteStep } from './actions/add-order-note';
import { changeOrderStatusStep } from './actions/change-order-status';
import { generateCouponStep } from './actions/generate-coupon';
import { sendEmailStep } from './actions/send-email';
import { EmailActions } from './actions/send-email/EmailActions';
import { sendInternalEmailStep } from './actions/send-internal-email';
import { waitStep } from './actions/wait';
import { criteriaStep } from './conditions/criteria';
import { defineStep } from './core/defineStep';
import { editorFooter } from './core/fields/EditorFrame';
import { createRegistry } from './core/registry';
import { cartAbandonedStep } from './triggers/cart-abandoned';
import { checkoutAbandonedStep } from './triggers/checkout-abandoned';
import { customerAccountCreatedStep } from './triggers/customer-account-created';
import { orderCreatedStep } from './triggers/order-created';
import { orderNoteAddedStep } from './triggers/order-note-added';
import { orderPaidStep } from './triggers/order-paid';
import { orderRefundedStep } from './triggers/order-refunded';
import { orderStatusChangedStep } from './triggers/order-status-changed';
import { reviewPostedStep } from './triggers/review-posted';

export type { Step } from './core/types';
export { plainSummary, SummaryText } from './core/defineStep';

const coreSteps = [
  orderCreatedStep,
  orderStatusChangedStep,
  orderPaidStep,
  orderRefundedStep,
  orderNoteAddedStep,
  cartAbandonedStep,
  checkoutAbandonedStep,
  customerAccountCreatedStep,
  reviewPostedStep,
  criteriaStep,
  sendEmailStep,
  sendInternalEmailStep,
  changeOrderStatusStep,
  addOrderNoteStep,
  generateCouponStep,
  waitStep,
  ...editionSteps,
];

/** Another plugin's steps, from the catalog: an email action gets Preview / Send test, an `editor` its button. */
const coreIds = new Set<string>(coreSteps.map((stepDef) => stepDef.id));
const moduleSteps = Object.entries(window.yayMatikMeta.automation.steps)
  .filter(([id, meta]) => !coreIds.has(id) && (meta?.kind === 'trigger' || meta?.kind === 'action'))
  .map(([id, meta]) =>
    defineStep({
      id: id as StepId,
      kind: meta!.kind,
      ...(meta?.email ? { Actions: EmailActions } : {}),
      ...(meta?.editor ? { Footer: editorFooter(id, meta.editor, meta.label) } : {}),
    }),
  );

/**
 * Every step the editor knows. To add a core one: create its folder under triggers/, conditions/ or
 * actions/ (named after the step id in kebab-case), add its PHP class, and list it here; a Pro one goes in
 * `src/pro/` and its list.
 */
export const { steps, newNodeData, withStep, stepOf } = createRegistry([...coreSteps, ...moduleSteps], 'condition');
