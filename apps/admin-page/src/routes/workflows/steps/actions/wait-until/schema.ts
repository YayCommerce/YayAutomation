import * as v from 'valibot';
import { getSettings, gmdateI18n } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

import { schemaFromFields } from '../../core/fields/catalogSchema';

/** 'HH:MM', 00:00–23:59. */
const TIME = /^(?:[01]\d|2[0-3]):[0-5]\d$/;

export type WaitUntilConfig = { time: string; weekdays: string[] };

const catalog = window.yayMatikMeta.automation.steps.wait_until;

/** A stored 'HH:MM' in the site's time format, e.g. "9:15 am"; anything else as it is. */
export const timeText = (time: unknown) =>
  TIME.test(String(time)) ? gmdateI18n(getSettings().formats.time, `1970-01-01T${time}:00Z`) : String(time ?? '');

/** PHP `sanitize()`: a missing time stays blank, an issue, never the 09:00 a new step starts at. */
export const normalize = (config: Record<string, unknown>): WaitUntilConfig => ({
  time: typeof config.time === 'string' ? config.time.trim() : '',
  weekdays: Array.isArray(config.weekdays) ? config.weekdays.map(String) : [],
});

/** PHP `WaitUntil::validate()`: a time not in 'HH:MM', blank included, gets its own message before the field checks. */
export const schema = v.pipe(
  v.record(v.string(), v.unknown()),
  v.rawCheck(({ dataset, addIssue }) => {
    if (!dataset.typed || TIME.test(String(dataset.value.time ?? ''))) return;
    addIssue({
      message: __('Enter a time, e.g. 9:15 am.', 'yaymatik'),
      path: [{ type: 'object', origin: 'value', input: dataset.value, key: 'time', value: dataset.value.time }],
    });
  }),
  schemaFromFields(catalog?.fields ?? [], catalog?.label ?? ''),
) as unknown as v.GenericSchema<WaitUntilConfig>;
