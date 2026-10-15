import { Ticket } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { defineStep, value } from '../../core/defineStep';
import { GenerateCouponForm } from './Form';
import { defaults, schema, type GenerateCouponConfig } from './schema';

const { currencySymbol } = window.yayMatikMeta.wc;

/** "10% off", "$5 off", "Free shipping". */
function discount({ discount_type, amount }: GenerateCouponConfig) {
  if (discount_type === 'free_shipping') return value(__('Free shipping', 'yaymatik'));
  const shown =
    amount.trim() && (discount_type === 'percent' ? `${amount.trim()}%` : `${currencySymbol}${amount.trim()}`);
  /* translators: %s: discount, e.g. "10%" */
  return sprintf(__('%s off', 'yaymatik'), value(shown || ''));
}

export const generateCouponStep = defineStep<GenerateCouponConfig>({
  id: 'generate_coupon',
  kind: 'action',
  label: __('Generate coupon', 'yaymatik'),
  description: __('Create a single-use WooCommerce coupon to send in a later email.', 'yaymatik'),
  icon: Ticket,
  schema,
  defaults,
  summary: (config) => {
    const days = config.expiry_days.trim();
    return days
      ? /* translators: 1: discount, e.g. "10% off", 2: number of days */
        sprintf(__('%1$s, expires in %2$s days', 'yaymatik'), discount(config), value(days))
      : /* translators: %s: discount, e.g. "10% off" */
        sprintf(__('%s, never expires', 'yaymatik'), discount(config));
  },
  Form: GenerateCouponForm,
});
