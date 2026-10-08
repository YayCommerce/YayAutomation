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
    minutes: _n('%s minute', '%s minutes', count, 'yay-automation'),
    /* translators: %s: number of hours */
    hours: _n('%s hour', '%s hours', count, 'yay-automation'),
    /* translators: %s: number of days */
    days: _n('%s day', '%s days', count, 'yay-automation'),
  }[unit];
  return sprintf(text, amount.trim());
}

export const waitStep = defineStep<WaitConfig>({
  id: 'wait',
  kind: 'action',
  label: __('Wait', 'yay-automation'),
  description: __('Pause the workflow for a while before the next step.', 'yay-automation'),
  icon: Clock,
  schema,
  defaults,
  normalize,
  summary: (config) =>
    /* translators: %s: duration, e.g. "7 days" */
    sprintf(__('Wait %s', 'yay-automation'), config.amount.trim() ? value(duration(config)) : PLACEHOLDER),
  Form: WaitForm,
});
