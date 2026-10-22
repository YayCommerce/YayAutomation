import { describe, expect, it } from 'vitest';

import { waitStep as step } from '.';
import { plainSummary } from '../../core/marks';

const delay = (value: unknown, unit: string) => ({ delay: { value, unit } });

describe('Wait config', () => {
  it('defaults to 1 day, as PHP does', () => {
    expect(step.defaults()).toEqual(delay(1, 'days'));
    expect(plainSummary(step.summary(step.defaults()))).toBe('Wait 1 day');
  });

  it('reads a numeric string as a number and an unknown unit as days', () => {
    expect(step.normalize(delay('3', 'years'))).toEqual(delay(3, 'days'));
    expect(step.normalize({})).toEqual(delay(null, 'days'));
    expect(step.normalize(delay('0x10', 'days'))).toEqual(delay(null, 'days'));
  });

  it('gives the messages PHP validate() gives', () => {
    expect(step.firstError(delay(52, 'weeks'))).toBeNull();
    expect(step.firstError(delay(0, 'days'))).toBe('Enter a whole number, 1 or more.');
    expect(step.firstError(delay(1.5, 'days'))).toBe('Enter a whole number, 1 or more.');
    expect(step.firstError(delay(null, 'days'))).toBe('Enter a whole number, 1 or more.');
    expect(step.firstError(delay(8761, 'hours'))).toBe('Wait at most a year.');
    expect(step.firstIssue(delay(0, 'days'))?.field).toBe('delay');
  });

  it('reads the delay on the card', () => {
    expect(plainSummary(step.summary(delay(2, 'weeks')))).toBe('Wait 2 weeks');
  });
});
