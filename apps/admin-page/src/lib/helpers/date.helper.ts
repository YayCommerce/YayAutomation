import { dateI18n, getSettings } from '@wordpress/date';

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
