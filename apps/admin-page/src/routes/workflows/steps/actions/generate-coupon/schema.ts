import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

/**
 * A WooCommerce coupon for later steps (`{{ coupon.code }}`). Numbers are kept as typed. Blank
 * `expiry_days` = never expires, blank `minimum_spend` = none; `amount` is ignored for free shipping.
 * Its PHP side isn't built yet (see `pending.ts`).
 */
export type DiscountType = 'percent' | 'fixed_cart' | 'free_shipping';
export type GenerateCouponConfig = {
  discount_type: DiscountType;
  amount: string;
  expiry_days: string;
  minimum_spend: string;
  individual_use: boolean;
  limit_to_customer: boolean;
  code_prefix: string;
};

const NUMBER = /^\s*(\d+\.?\d*|\.\d+)\s*$/;
const WHOLE = /^\s*\d+\s*$/;

/** Blank, or matches `pattern`. */
const optional = (pattern: RegExp, message: string) =>
  v.pipe(
    v.string(),
    v.check((value) => value.trim() === '' || pattern.test(value), message),
  );

export const schema = v.pipe(
  v.object({
    discount_type: v.picklist(['percent', 'fixed_cart', 'free_shipping']),
    amount: v.string(),
    expiry_days: optional(WHOLE, __('Enter a whole number of days.', 'yaymatik')),
    minimum_spend: optional(NUMBER, __('Enter an amount.', 'yaymatik')),
    individual_use: v.boolean(),
    limit_to_customer: v.boolean(),
    code_prefix: optional(/^[A-Za-z0-9_-]+$/, __('Use letters, numbers, - and _ only.', 'yaymatik')),
  }),
  v.forward(
    v.check(
      ({ discount_type, amount }) => {
        if (discount_type === 'free_shipping') return true;
        const number = Number(amount);
        return NUMBER.test(amount) && number > 0 && (discount_type !== 'percent' || number <= 100);
      },
      __('Enter an amount above 0 (up to 100 for a percentage).', 'yaymatik'),
    ),
    ['amount'],
  ),
);

export const defaults = (): GenerateCouponConfig => ({
  discount_type: 'percent',
  amount: '10',
  expiry_days: '30',
  minimum_spend: '',
  individual_use: false,
  limit_to_customer: true,
  code_prefix: '',
});
