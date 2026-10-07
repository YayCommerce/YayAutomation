import { __, sprintf } from '@wordpress/i18n';

import { defineBlock, value } from '../../core/defineBlock';
import { GenerateCouponForm } from './Form';
import { defaults, schema, type GenerateCouponConfig } from './schema';

const { currencySymbol } = window.yayAutomationMeta.wc;

/** "10% off", "$5 off", "Free shipping". */
function discount({ discount_type, amount }: GenerateCouponConfig) {
  if (discount_type === 'free_shipping') return value(__('Free shipping', 'yay-automation'));
  const shown =
    amount.trim() && (discount_type === 'percent' ? `${amount.trim()}%` : `${currencySymbol}${amount.trim()}`);
  /* translators: %s: discount, e.g. "10%" */
  return sprintf(__('%s off', 'yay-automation'), value(shown || ''));
}

export const generateCouponBlock = defineBlock<GenerateCouponConfig>({
  id: 'generate_coupon',
  kind: 'action',
  label: __('Generate coupon', 'yay-automation'),
  description: __('Create a single-use WooCommerce coupon to send in a later email.', 'yay-automation'),
  schema,
  defaults,
  summary: (config) => {
    const days = config.expiry_days.trim();
    return days
      ? /* translators: 1: discount, e.g. "10% off", 2: number of days */
        sprintf(__('%1$s, expires in %2$s days', 'yay-automation'), discount(config), value(days))
      : /* translators: %s: discount, e.g. "10% off" */
        sprintf(__('%s, never expires', 'yay-automation'), discount(config));
  },
  Form: GenerateCouponForm,
});
