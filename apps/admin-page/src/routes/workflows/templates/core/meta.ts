import type { TemplateCategory, WorkflowGraph } from '@yay-automation/types';
import { __ } from '@wordpress/i18n';

import { fieldByPath } from '../../steps/core/data';
import { isPendingField, isPendingStep } from '../../steps/core/pending';
import { templatePaths } from '../../steps/core/template';

/** In library chip order. */
export const categoryLabels: Record<TemplateCategory, string> = {
  recover_carts: __('Recover carts', 'yay-automation'),
  post_purchase: __('Post-purchase', 'yay-automation'),
  customer_lifecycle: __('Customer lifecycle', 'yay-automation'),
  alerts: __('Alerts', 'yay-automation'),
};

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
