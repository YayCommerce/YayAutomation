import { Clock } from 'lucide-react';
import { __, _n, sprintf } from '@wordpress/i18n';

import { defineStep, PLACEHOLDER, value } from '../../core/defineStep';
import { WaitForm } from './Form';
import { defaults, normalize, schema, type WaitConfig } from './schema';

/** "1 day", "7 days". */
function duration({ amount, unit }: WaitConfig) {
  const count = Number(amount);
  const text = {
    /* translators: %s: number of minutes */
    minutes: _n('%s minute', '%s minutes', count, 'yaymatik'),
    /* translators: %s: number of hours */
    hours: _n('%s hour', '%s hours', count, 'yaymatik'),
    /* translators: %s: number of days */
    days: _n('%s day', '%s days', count, 'yaymatik'),
  }[unit];
  return sprintf(text, amount.trim());
}

export const waitStep = defineStep<WaitConfig>({
  id: 'wait',
  kind: 'action',
  label: __('Wait', 'yaymatik'),
  description: __('Pause the workflow for a while before the next step.', 'yaymatik'),
  icon: Clock,
  schema,
  defaults,
  normalize,
  summary: (config) =>
    /* translators: %s: duration, e.g. "7 days" */
    sprintf(__('Wait %s', 'yaymatik'), config.amount.trim() ? value(duration(config)) : PLACEHOLDER),
  Form: WaitForm,
});
