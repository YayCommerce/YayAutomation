import { dateI18n, getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

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

/** "Oct 2": the year only when it isn't this year. */
export const shortDay = (date: string) =>
  formatDate(date, formatDate(date, 'Y') === formatDate(undefined, 'Y') ? 'M j' : 'M j, Y');

/** "Oct 2 at 3:39 PM": the year only when it isn't this year; the time in the site's time format. */
export function shortDayTime(date: string) {
  return sprintf(/* translators: 1: date, 2: time */ __('%1$s at %2$s', 'yaymatik'), shortDay(date), formatTime(date));
}
