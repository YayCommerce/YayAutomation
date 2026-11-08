import { __, sprintf } from '@wordpress/i18n';

import { defineStep, value } from '../../core/defineStep';
import type { Config } from '../../core/types';
import { Hints } from '../../triggers/core/NoSettings';

const field = (key: string) => window.yayMatikMeta.automation.steps.wait_until?.fields.find((f) => f.key === key);
const label = (key: string, raw: unknown) =>
  field(key)?.options?.find((option) => option.value === String(raw))?.label ?? String(raw);

/** "Mon–Fri", "Mon, Wed, Sat–Sun"; three or more days in a row read as a range. */
export function weekdayText(days: unknown): string {
  const sorted = [...new Set(Array.isArray(days) ? days.map(Number) : [])].filter((d) => d >= 1 && d <= 7).sort();
  const parts: string[] = [];
  for (let i = 0; i < sorted.length;) {
    let end = i;
    while (end + 1 < sorted.length && sorted[end + 1] === sorted[end] + 1) end++;
    const run = sorted.slice(i, end + 1).map((day) => label('weekdays', day));
    parts.push(...(run.length >= 3 ? [`${run[0]}–${run[run.length - 1]}`] : run));
    i = end + 1;
  }
  return parts.join(', ');
}

/** The store's time zone as WordPress names it, else its UTC offset. */
function storeZone(): string {
  const { timezoneString, gmtOffset } = window.yayMatikMeta.wp;
  if (timezoneString) return timezoneString;
  return `UTC${gmtOffset < 0 ? '−' : '+'}${Math.abs(gmtOffset)}`;
}

/** Form and checks come from includes/Workflow/Actions/WaitUntil.php `fields()`. */
export const waitUntilStep = defineStep({
  id: 'wait_until',
  kind: 'action',
  summaryValues: {
    weekdays: ({ weekdays }: Config) => value(weekdayText(weekdays) || __('Any day', 'yaymatik')),
  },
  Footer: ({ config }) => (
    <Hints
      hints={[
        sprintf(
          /* translators: 1: time zone, e.g. "Asia/Ho_Chi_Minh"; 2: a time, e.g. "9:00 AM" */
          __('Store time (%1$s). Past %2$s, it waits for the next chosen day.', 'yaymatik'),
          storeZone(),
          label('hour', config.hour),
        ),
      ]}
    />
  ),
});
