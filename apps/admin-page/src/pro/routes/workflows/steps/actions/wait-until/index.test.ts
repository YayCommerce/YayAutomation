import { afterEach, describe, expect, it } from 'vitest';
import { getSettings, setSettings } from '@wordpress/date';

import { steps } from '@/routes/workflows/steps';
import { plainSummary } from '@/routes/workflows/steps/core/marks';
import { weekdayText } from '.';

const dateSettings = getSettings();
const timeFormat = (time: string) => setSettings({ ...dateSettings, formats: { ...dateSettings.formats, time } });

afterEach(() => setSettings(dateSettings));

describe('Wait until', () => {
  it('reads runs of three days or more as a range', () => {
    expect(weekdayText(['1', '2', '3', '4', '5'])).toBe('Mon–Fri');
    expect(weekdayText(['6', '1', '3', '7'])).toBe('Mon, Wed, Sat, Sun');
    expect(weekdayText(['1', '2', '3', '5', '6', '7'])).toBe('Mon–Wed, Fri–Sun');
    expect(weekdayText([])).toBe('');
  });

  it('summarizes the time in the site’s 12-hour format, and the days, any day when none', () => {
    timeFormat('g:i a');
    const step = steps.wait_until;
    expect(plainSummary(step.summary({ time: '11:10', weekdays: ['1', '2', '3', '4', '5'] }))).toBe(
      'Until 11:10 am · Mon–Fri',
    );
    expect(plainSummary(step.summary({ time: '00:07', weekdays: [] }))).toBe('Until 12:07 am · Any day');
  });

  it('summarizes the time in the site’s 24-hour format', () => {
    timeFormat('H:i');
    expect(plainSummary(steps.wait_until.summary({ time: '21:37', weekdays: [] }))).toBe('Until 21:37 · Any day');
  });

  it('starts a new step at 9:00', () => {
    expect(steps.wait_until.defaults()).toEqual({ time: '09:00', weekdays: [] });
    expect(steps.wait_until.firstError(steps.wait_until.defaults())).toBeNull();
  });

  it('takes any minute, with PHP’s message for anything else', () => {
    const step = steps.wait_until;
    for (const time of ['00:00', '11:10', '09:07', '23:59']) expect(step.firstError({ time, weekdays: [] })).toBeNull();
    const bad = [
      { time: '9:15' },
      { time: '24:00' },
      { time: '09:60' },
      { time: '09:15:00' },
      { time: '' },
      { hour: '9' },
    ];
    for (const config of bad) {
      expect(step.firstIssue({ ...config, weekdays: [] })).toEqual({
        message: 'Enter a time, e.g. 9:15 am.',
        field: 'time',
      });
    }
    expect(plainSummary(step.summary({ hour: '9', weekdays: [] }))).toBe('Until Not set · Any day');
  });
});
