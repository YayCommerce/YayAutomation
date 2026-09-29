import { __, sprintf } from '@wordpress/i18n';

import { defineBlock, PLACEHOLDER } from '../../core/defineBlock';
import { OrderTotalForm } from './Form';
import { currencySymbol, defaults, operatorLabels, schema, type OrderTotalConfig } from './schema';

export const orderTotalBlock = defineBlock<OrderTotalConfig>({
  id: 'order_total',
  kind: 'condition',
  app: 'woocommerce',
  label: __('Order total', 'yay-automation'),
  description: __(
    'Continues on the “Then” branch when the order total matches, otherwise on the “Otherwise” branch.',
    'yay-automation',
  ),
  schema,
  defaults,
  summary: ({ operator, amount }) =>
    /* translators: 1: comparison such as "is greater than", 2: amount with currency symbol */
    sprintf(
      __('Order total %1$s %2$s', 'yay-automation'),
      operatorLabels[operator] ?? PLACEHOLDER,
      amount === null || Number.isNaN(amount) ? PLACEHOLDER : `${currencySymbol}${amount}`,
    ),
  Form: OrderTotalForm,
});
