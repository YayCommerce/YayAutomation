import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

export const operators = ['gt', 'gte', 'lt', 'lte'] as const;
export type Operator = (typeof operators)[number];

/** Mirrors src/Engine/Blocks/Conditions/OrderTotal.php. `amount` is null until the user enters one. */
export type OrderTotalConfig = { operator: Operator; amount: number | null };

export const operatorLabels: Record<Operator, string> = {
  gt: __('is greater than', 'yay-automation'),
  gte: __('is greater than or equal to', 'yay-automation'),
  lt: __('is less than', 'yay-automation'),
  lte: __('is less than or equal to', 'yay-automation'),
};

export const currencySymbol = window.yayAutomationMeta.wc.currencySymbol;
const amountError = __('Enter an amount of 0 or more.', 'yay-automation');

export const schema = v.object({
  operator: v.picklist(operators, __('Choose a comparison.', 'yay-automation')),
  amount: v.pipe(
    v.nullable(v.number(amountError)),
    v.check((amount) => amount !== null && amount >= 0, amountError),
  ),
});

export const defaults = (): OrderTotalConfig => ({ operator: 'gt', amount: null });
