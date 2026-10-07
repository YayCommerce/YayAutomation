import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

/**
 * Pauses the run, then carries on with the next step; conditions after it read fresh data.
 * `amount` is kept as typed. Its PHP side isn't built yet (see `pending.ts`).
 */
export type WaitUnit = 'minutes' | 'hours' | 'days';
export type WaitConfig = { amount: string; unit: WaitUnit };

const UNITS: WaitUnit[] = ['minutes', 'hours', 'days'];

/** At most a year, in each unit. */
const MAX: Record<WaitUnit, number> = { minutes: 525600, hours: 8760, days: 365 };

export const schema = v.pipe(
  v.object({
    amount: v.pipe(
      v.string(),
      v.check(
        (amount) => /^\s*\d+\s*$/.test(amount) && Number(amount) >= 1,
        __('Enter a whole number, 1 or more.', 'yay-automation'),
      ),
    ),
    unit: v.picklist(UNITS),
  }),
  v.forward(
    v.check(({ amount, unit }) => Number(amount) <= MAX[unit], __('Wait at most a year.', 'yay-automation')),
    ['amount'],
  ),
);

export const defaults = (): WaitConfig => ({ amount: '1', unit: 'days' });

/** Stored config may hold a number or an unknown unit: keep the card and form from breaking. */
export const normalize = (config: Record<string, unknown>): WaitConfig => ({
  amount: String(config.amount ?? defaults().amount),
  unit: UNITS.includes(config.unit as WaitUnit) ? (config.unit as WaitUnit) : defaults().unit,
});
