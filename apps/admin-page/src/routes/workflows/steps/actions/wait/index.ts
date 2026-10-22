import { _n, sprintf } from '@wordpress/i18n';

import { defineStep, value } from '../../core/defineStep';
import { WaitForm } from './Form';
import { normalize, schema, type WaitConfig } from './schema';

/** "1 day", "7 days"; '' while blank. */
function duration({ delay }: WaitConfig) {
  if (delay.value === null) return '';
  const text = {
    /* translators: %s: number of minutes */
    minutes: _n('%s minute', '%s minutes', delay.value, 'yaymatik'),
    /* translators: %s: number of hours */
    hours: _n('%s hour', '%s hours', delay.value, 'yaymatik'),
    /* translators: %s: number of days */
    days: _n('%s day', '%s days', delay.value, 'yaymatik'),
    /* translators: %s: number of weeks */
    weeks: _n('%s week', '%s weeks', delay.value, 'yaymatik'),
  }[delay.unit];
  return value(sprintf(text, String(delay.value)));
}

export const waitStep = defineStep<WaitConfig>({
  id: 'wait',
  kind: 'action',
  schema,
  normalize,
  summaryValues: { delay: duration },
  Form: WaitForm,
});
