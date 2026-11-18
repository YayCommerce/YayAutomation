import { settingRanges } from '@edition/settings';
import * as v from 'valibot';
import { describe, expect, it } from 'vitest';

import { changedSettings, settingsSchema, type SettingsValues } from './settingsForm';

const saved: SettingsValues = {
  cartTracking: false,
  abandonMinutes: 60,
  captureGuestEmail: true,
  cartRetentionDays: 60,
  runRetentionDays: 90,
  unsubscribeText: '',
};

/** The issue messages for `saved` with one field changed. */
const issues = (changes: Partial<SettingsValues>) =>
  (v.safeParse(settingsSchema(saved), { ...saved, ...changes }).issues ?? []).map((issue) => issue.message);

describe('settingsSchema', () => {
  const ranges = Object.entries(settingRanges).flatMap(([key, range]) =>
    range ? [[key as keyof typeof settingRanges, ...range] as const] : [],
  );

  it.each(ranges)('%s takes whole numbers from %d to %d, like PHP', (key, min, max) => {
    const message = `Enter a whole number from ${min} to ${max}.`;
    expect(issues({ [key]: min - 1 })).toEqual([message]);
    expect(issues({ [key]: min })).toEqual([]);
    expect(issues({ [key]: max })).toEqual([]);
    expect(issues({ [key]: max + 1 })).toEqual([message]);
    expect(issues({ [key]: 60.5 })).toEqual([message]);
    expect(issues({ [key]: null })).toEqual([message]);
  });
});

describe('the edition’s ranges', () => {
  it('match PHP: the free edition saves retention up to 60 and 90 days and shows no abandon minutes', () => {
    expect(settingRanges).toEqual(
      process.env.YAYMATIK_EDITION !== 'lite'
        ? { abandonMinutes: [15, 1440], cartRetentionDays: [7, 365], runRetentionDays: [7, 730] }
        : { abandonMinutes: null, cartRetentionDays: [7, 60], runRetentionDays: [7, 90] },
    );
  });

  it('let a stored value above the range through untouched, as a save sends changes only', () => {
    const stored = { ...saved, cartRetentionDays: 9999 };
    expect(v.safeParse(settingsSchema(stored), stored).success).toBe(true);
    expect(v.safeParse(settingsSchema(stored), { ...stored, cartRetentionDays: 9998 }).success).toBe(false);
  });
});

describe('changedSettings', () => {
  it('sends only what differs from the saved settings', () => {
    expect(changedSettings(saved, saved)).toEqual({});
    expect(changedSettings({ ...saved, abandonMinutes: 30, cartTracking: true }, saved)).toEqual({
      abandonMinutes: 30,
      cartTracking: true,
    });
  });
});
