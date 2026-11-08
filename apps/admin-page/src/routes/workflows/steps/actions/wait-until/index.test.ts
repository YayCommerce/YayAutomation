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

  it('summarizes the hour and the days, any day when none', () => {
    const step = steps.wait_until;
    expect(plainSummary(step.summary({ hour: '9', weekdays: ['1', '2', '3', '4', '5'] }))).toBe(
      'Until 9:00 am · Mon–Fri',
    );
    expect(plainSummary(step.summary({ hour: '9', weekdays: [] }))).toBe('Until 9:00 am · Any day');
  });

  it('takes midnight as a value, not a blank', () => {
    const step = steps.wait_until;
    expect(step.firstError({ hour: '0', weekdays: [] })).toBeNull();
    expect(plainSummary(step.summary({ hour: '0', weekdays: [] }))).toBe('Until 12:00 am · Any day');
  });
});
