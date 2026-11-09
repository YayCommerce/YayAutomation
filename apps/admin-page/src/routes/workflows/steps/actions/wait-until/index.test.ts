import { describe, expect, it } from 'vitest';

import { weekdayText } from '.';
import { steps } from '../..';
import { plainSummary } from '../../core/marks';

describe('Wait until', () => {
  it('reads runs of three days or more as a range', () => {
    expect(weekdayText(['1', '2', '3', '4', '5'])).toBe('Mon–Fri');
    expect(weekdayText(['6', '1', '3', '7'])).toBe('Mon, Wed, Sat, Sun');
    expect(weekdayText(['1', '2', '3', '5', '6', '7'])).toBe('Mon–Wed, Fri–Sun');
    expect(weekdayText([])).toBe('');
  });

  it('summarizes the time and the days, any day when none', () => {
    const step = steps.wait_until;
    expect(plainSummary(step.summary({ time: '09:15', weekdays: ['1', '2', '3', '4', '5'] }))).toBe(
      'Until 9:15 am · Mon–Fri',
    );
    expect(plainSummary(step.summary({ time: '00:00', weekdays: [] }))).toBe('Until 12:00 am · Any day');
  });

  it('starts a new step at 9:00', () => {
    expect(steps.wait_until.defaults()).toEqual({ time: '09:00', weekdays: [] });
    expect(steps.wait_until.firstError(steps.wait_until.defaults())).toBeNull();
  });

  it('takes times on the 15-minute list only, with PHP’s message', () => {
    const step = steps.wait_until;
    for (const time of ['00:00', '09:15', '23:45']) expect(step.firstError({ time, weekdays: [] })).toBeNull();
    for (const time of ['', '9:15', '09:10', '24:00', '09:15:00']) {
      expect(step.firstIssue({ time, weekdays: [] })).toEqual({
        message: 'Choose a time in 15-minute steps.',
        field: 'time',
      });
    }
  });
});
