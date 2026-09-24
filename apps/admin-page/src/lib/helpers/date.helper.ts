import { dateI18n, getDate, getSettings } from '@wordpress/date';

export { getWpTimezone } from '@yay-automation/utils';

export function formatDate(date: Date | string | undefined, format?: string) {
  const { formats } = getSettings();
  return dateI18n(format ?? formats.date, date);
}

export function formatTime(date: Date | string | undefined, format?: string) {
  const { formats } = getSettings();
  return dateI18n(format ?? formats.time, date);
}

export function formatDateTime(date: Date | string | undefined, format?: string) {
  const { formats } = getSettings();
  return dateI18n(format ?? formats.datetime, date);
}

/** parse date string to js date with wp timezone */
export function parseWpDate(date: string | undefined) {
  return getDate(date);
}

/** get current date in js date with wp timezone */
export function getWpNow() {
  return getDate();
}
