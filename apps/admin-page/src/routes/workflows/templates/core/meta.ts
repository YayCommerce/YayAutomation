import type { TemplateCategory, TemplateWorkflow, WorkflowGraph } from '@yaymatik/types';
import { __ } from '@wordpress/i18n';

import { fieldByPath } from '../../steps/core/data';
import { isPendingField, isPendingStep } from '../../steps/core/pending';
import { templatePaths } from '../../steps/core/template';

/** In library chip order. */
export const categoryLabels: Record<TemplateCategory, string> = {
  recover_carts: __('Recover carts', 'yaymatik'),
  post_purchase: __('Post-purchase', 'yaymatik'),
  customer_lifecycle: __('Customer lifecycle', 'yaymatik'),
  alerts: __('Alerts', 'yaymatik'),
};

/**
 * "Most popular" rank until installs are tracked: expected demand, carts first, the staff alert last.
 */
export const workflowRank: Record<TemplateWorkflow, number> = {
  abandoned_cart: 0,
  abandoned_checkout: 1,
  first_order_thank_you: 2,
  cross_sell_upsell: 3,
  review_request: 4,
  welcome: 5,
  win_back: 6,
  high_value_order_alert: 7,
};

/** The graph emails the customer: Use template asks to review the emails before activating. */
export const sendsCustomerEmail = (graph: WorkflowGraph) => graph.nodes.some(({ data }) => data.block === 'send_email');

const strings = (value: unknown): string[] =>
  typeof value === 'string' ? [value] : value && typeof value === 'object' ? Object.values(value).flatMap(strings) : [];

/**
 * A step or variable of the graph has no PHP side yet (see `steps/core/pending.ts`): the server
 * would reject the draft, so Use template stays locked. Checks every config string, as a field key
 * (criteria) or as text with merge tags.
 */
export const isComingSoon = (graph: WorkflowGraph) =>
  graph.nodes.some(
    ({ data }) =>
      (data.block && isPendingStep(data.block)) ||
      strings(data.config).some(
        (text) =>
          isPendingField(text) || templatePaths(text).some((path) => isPendingField(fieldByPath(path)?.key ?? '')),
      ),
  );
