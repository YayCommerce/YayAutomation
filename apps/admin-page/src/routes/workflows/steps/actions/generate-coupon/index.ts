import { __, _n, sprintf } from '@wordpress/i18n';

import { defineStep, value } from '../../core/defineStep';
import type { Config } from '../../core/types';
import { FreeShippingWarning } from './FreeShippingWarning';

const { currencySymbol } = window.yayMatikMeta.wc;

/**
 * A WooCommerce coupon for later steps (`{{ steps.<id>.code }}`). Blank (null) `expiry_days` =
 * never expires, null `minimum_spend` = none; `amount` is unused for free shipping.
 * Form and checks come from includes/Workflow/Steps/Actions/GenerateCoupon.php `fields()`.
 */
export type DiscountType = 'percent' | 'fixed_cart' | 'free_shipping';
export type GenerateCouponConfig = {
  discount_type: DiscountType;
  amount: number | null;
  expiry_days: number | null;
  minimum_spend: number | null;
  individual_use: boolean;
  limit_to_customer: boolean;
  code_prefix: string;
};

/**
 * A stored number as PHP `GenerateCoupon::sanitize()` reads it: configs saved before the fields
 * came from PHP keep numbers as text ("30"), and blank text means none.
 */
function toNumber(raw: unknown, fallback: number | null): number | null {
  if (raw === null || typeof raw === 'number') return raw;
  if (typeof raw !== 'string') return fallback;
  const text = raw.trim();
  if (text === '') return null;
  return /^(\d+\.?\d*|\.\d+)$/.test(text) ? Number(text) : fallback;
}

/** "10% off", "$5 off", "Free shipping". */
function discount({ discount_type, amount }: GenerateCouponConfig) {
  if (discount_type === 'free_shipping') return value(__('Free shipping', 'yaymatik'));
  if (amount === null) return '';
  const shown = discount_type === 'percent' ? `${amount}%` : `${currencySymbol}${amount}`;
  /* translators: %s: discount, e.g. "10%" */
  return sprintf(__('%s off', 'yaymatik'), value(shown));
}

/** "expires in 30 days", "never expires". */
function expiry({ expiry_days: days }: GenerateCouponConfig) {
  if (days === null) return __('never expires', 'yaymatik');
  /* translators: %s: number of days */
  return sprintf(_n('expires in %s day', 'expires in %s days', days, 'yaymatik'), value(String(days)));
}

export const generateCouponStep = defineStep<GenerateCouponConfig>({
  id: 'generate_coupon',
  kind: 'action',
  // Mirrors the PHP step's own `sanitize()`, numbers as above.
  normalize: (config: Config) => {
    const fallback = generateCouponStep.defaults() as GenerateCouponConfig;
    const text = (key: 'discount_type' | 'code_prefix') =>
      typeof config[key] === 'string' ? (config[key] as string) : fallback[key];
    const flag = (key: 'individual_use' | 'limit_to_customer') =>
      typeof config[key] === 'boolean' ? (config[key] as boolean) : fallback[key];
    return {
      discount_type: text('discount_type') as DiscountType,
      amount: toNumber(config.amount, fallback.amount),
      expiry_days: toNumber(config.expiry_days, fallback.expiry_days),
      minimum_spend: toNumber(config.minimum_spend, fallback.minimum_spend),
      individual_use: flag('individual_use'),
      limit_to_customer: flag('limit_to_customer'),
      code_prefix: text('code_prefix'),
    };
  },
  summaryValues: { discount_type: discount, expiry_days: expiry },
  Footer: FreeShippingWarning,
});
