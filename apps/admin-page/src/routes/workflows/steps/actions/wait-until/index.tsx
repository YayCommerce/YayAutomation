import { Clock } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { defineStep, value } from '../../core/defineStep';
import { Hints } from '../../triggers/core/NoSettings';
import { WaitUntilForm } from './Form';
import { normalize, schema, timeText, type WaitUntilConfig } from './schema';

const label = (raw: unknown) =>
  window.yayMatikMeta.automation.steps.wait_until?.fields
    .find((f) => f.key === 'weekdays')
    ?.options?.find((option) => option.value === String(raw))?.label ?? String(raw);

/** "Mon–Fri", "Mon, Wed, Sat–Sun"; three or more days in a row read as a range. */
export function weekdayText(days: unknown): string {
  const sorted = [...new Set(Array.isArray(days) ? days.map(Number) : [])].filter((d) => d >= 1 && d <= 7).sort();
  const parts: string[] = [];
  for (let i = 0; i < sorted.length;) {
    let end = i;
    while (end + 1 < sorted.length && sorted[end + 1] === sorted[end] + 1) end++;
    const run = sorted.slice(i, end + 1).map(label);
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

export const waitUntilStep = defineStep<WaitUntilConfig>({
  id: 'wait_until',
  kind: 'action',
  schema,
  normalize,
  // PHP's time field is blank by default, so a config without a time stays an issue.
  defaults: () => ({ time: '09:00', weekdays: [] }),
  summaryValues: {
    time: ({ time }) => value(timeText(time)),
    weekdays: ({ weekdays }) => value(weekdayText(weekdays) || __('Any day', 'yaymatik')),
  },
  Form: WaitUntilForm,
  Footer: ({ config }) => (
    <Hints
      hints={[
        {
          text: config.time
            ? sprintf(
                /* translators: 1: time zone, e.g. "Asia/Ho_Chi_Minh"; 2: a time, e.g. "9:15 AM" */
                __('Store time (%1$s). Past %2$s, it waits for the next chosen day.', 'yaymatik'),
                storeZone(),
                timeText(config.time),
              )
            : /* translators: %s: time zone, e.g. "Asia/Ho_Chi_Minh" */
              sprintf(__('Store time (%s).', 'yaymatik'), storeZone()),
          icon: <Clock aria-hidden />,
        },
      ]}
    />
  ),
});
