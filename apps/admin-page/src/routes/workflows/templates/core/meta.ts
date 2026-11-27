import type { TemplateCategory, TemplateWorkflow, WorkflowGraph } from '@yaymatik/types';
import { __ } from '@wordpress/i18n';

import { stepOf } from '../../steps';
import { flatRules, type CriteriaItem } from '../../steps/conditions/criteria/schema';

/** In library chip order. */
export const categoryLabels: Record<TemplateCategory, string> = {
  recover_carts: __('Recover carts', 'yaymatik-automation-for-woocommerce'),
  post_purchase: __('Post-purchase', 'yaymatik-automation-for-woocommerce'),
  customer_lifecycle: __('Customer lifecycle', 'yaymatik-automation-for-woocommerce'),
  alerts: __('Alerts', 'yaymatik-automation-for-woocommerce'),
};

/**
 * "Most popular" rank until installs are tracked: expected demand, carts first, the staff alert last.
 * Another plugin's workflows come after these.
 */
export const workflowRank: Partial<Record<TemplateWorkflow, number>> = {
  abandoned_cart: 0,
  abandoned_checkout: 1,
  first_order_thank_you: 2,
  cross_sell_upsell: 3,
  review_request: 4,
  welcome: 5,
  win_back: 6,
  high_value_order_alert: 7,
};

/** A workflow's "Most popular" place. */
export const rankOf = (workflow: TemplateWorkflow) => workflowRank[workflow] ?? Object.keys(workflowRank).length;

/** The graph emails the customer: Use template asks to review the emails before activating. */
export const sendsCustomerEmail = (graph: WorkflowGraph) =>
  graph.nodes.some(({ data }) => stepOf(data)?.email === 'customer');

/** The graph starts from a cart trigger while cart tracking is off. */
export const needsCartTracking = (graph: WorkflowGraph) => {
  // Read per call: saving Settings changes it. While WooCommerce is off, "Install WooCommerce" says enough.
  const { active, cartTracking } = window.yayMatikMeta.wc;
  return (
    active &&
    !cartTracking &&
    graph.nodes.some((node) => node.kind === 'trigger' && stepOf(node.data)?.category === 'cart')
  );
};

/** The graph reads whether a product is left to review while the store has reviews off. */
export const needsProductReviews = (graph: WorkflowGraph) => {
  // Fixed at page load: it changes in WooCommerce's settings, so the note stays until a reload.
  const { active, reviewsEnabled } = window.yayMatikMeta.wc;
  return (
    active &&
    !reviewsEnabled &&
    graph.nodes.some(
      (node) =>
        node.kind === 'condition' &&
        flatRules((node.data.config.rules ?? []) as CriteriaItem[]).some(
          (rule) => rule.field === 'order.has_products_not_reviewed',
        ),
    )
  );
};
