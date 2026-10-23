import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

/** PHP `Wait::validate()`'s rules; a blank value is an error here, where PHP's sanitize fills in 1. */
export type WaitUnit = 'minutes' | 'hours' | 'days' | 'weeks';
export type WaitConfig = { delay: { value: number | null; unit: WaitUnit } };

const UNITS: WaitUnit[] = ['minutes', 'hours', 'days', 'weeks'];

/** At most a year, in each unit. */
const MAX: Record<WaitUnit, number> = { minutes: 525600, hours: 8760, days: 365, weeks: 52 };

export const schema = v.object({
  delay: v.pipe(
    v.object({ value: v.nullable(v.number()), unit: v.picklist(UNITS) }),
    v.check(
      ({ value }) => value !== null && Number.isInteger(value) && value >= 1,
      __('Enter a whole number, 1 or more.', 'yaymatik'),
    ),
    v.check(({ value, unit }) => value === null || value <= MAX[unit], __('Wait at most a year.', 'yaymatik')),
  ),
});

/** Decimal text → number, like PHP `is_numeric()` (no hex or binary); anything else → null. */
export const toNumber = (text: string) => (/^\s*-?\d+(\.\d+)?\s*$/.test(text) ? Number(text) : null);

/** Stored config may hold a numeric string or an unknown unit: keep the card and form from breaking. */
export const normalize = (config: Record<string, unknown>): WaitConfig => {
  const delay = (config.delay ?? {}) as { value?: unknown; unit?: unknown };
  const number = typeof delay.value === 'string' ? toNumber(delay.value) : delay.value;
  return {
    delay: {
      value: typeof number === 'number' && Number.isFinite(number) ? number : null,
      unit: UNITS.includes(delay.unit as WaitUnit) ? (delay.unit as WaitUnit) : 'days',
    },
  };
};
