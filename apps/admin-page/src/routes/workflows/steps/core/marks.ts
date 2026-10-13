import { __ } from '@wordpress/i18n';

// Invisible characters around a part of a summary mark how `SummaryText` shows it, so summaries
// stay plain strings (sprintf, i18n) yet render rich.
const EMPTY_MARK = '⁠';
const VALUE_MARK = '⁡';

/** Shown in summaries where a required value is still missing: an italic "Not set". */
export const PLACEHOLDER = `${EMPTY_MARK}${__('Not set', 'yay-automation')}${EMPTY_MARK}`;

/** A configured value in a summary, shown bold; blank → the italic placeholder. */
export const value = (text: string) => (text.trim() ? `${VALUE_MARK}${text}${VALUE_MARK}` : PLACEHOLDER);

/** A summary as plain text, marks removed: for `title`, `aria-label` and other places without formatting. */
export const plainSummary = (text: string) => text.replace(new RegExp(`[${EMPTY_MARK}${VALUE_MARK}]`, 'g'), '');

/** Group 1: a placeholder's text; group 2: a value's text. */
export const MARKED = new RegExp(
  `${EMPTY_MARK}([^${EMPTY_MARK}]*)${EMPTY_MARK}|${VALUE_MARK}([^${VALUE_MARK}]*)${VALUE_MARK}`,
  'g',
);
