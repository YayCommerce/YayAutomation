import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import { defineBlock } from '../../core/defineBlock';

/** Mirrors includes/Engine/Blocks/Triggers/OrderCreated.php. Nothing to configure. */
export const orderCreatedBlock = defineBlock<Record<string, never>>({
  id: 'order_created',
  kind: 'trigger',
  label: __('Order created', 'yay-automation'),
  description: __('This workflow starts when a new order is created', 'yay-automation'),
  schema: v.object({}),
  defaults: () => ({}),
  normalize: () => ({}),
  summary: () => __('This workflow starts when a new order is created', 'yay-automation'),
});
