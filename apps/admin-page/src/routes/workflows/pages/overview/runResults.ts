import type { StepId } from '@yaymatik/types';
import { __, sprintf } from '@wordpress/i18n';

import { shortDayTime } from '@/lib/helpers/date.helper';
import { plainSummary, steps } from '../../steps';
import { catalogLabel } from '../../steps/core/summary';
import { fillTemplate } from '../../steps/core/template';
import type { RunContext, RunStepRow } from '../../steps/core/types';

const text = (value: unknown) => (typeof value === 'string' ? value : '');

/** The step's card text, from the config it ran with. */
const summary = (step: RunStepRow, run: RunContext) =>
  step.stepType && steps[step.stepType] ? plainSummary(steps[step.stepType].summary(step.input, run.triggerId)) : '';

type ResultText = (step: RunStepRow, run: RunContext) => string;

/** Core's steps; another one may bring its own `result`. */
const results: Partial<Record<StepId, ResultText>> = {
  condition: (step) => (step.output.result === 'yes' ? __('Yes', 'yaymatik') : __('No', 'yaymatik')),
  wait: (step, run) => {
    if (step.status === 'waiting') {
      return run.waitUntil
        ? sprintf(
            /* translators: %s: date and time, e.g. "Oct 9 at 3:00 PM" */
            __('until %s', 'yaymatik'),
            shortDayTime(run.waitUntil),
          )
        : '';
    }
    return step.finishedAt
      ? sprintf(
          /* translators: 1: how long it waited, e.g. "Wait 1 day"; 2: date and time, e.g. "Oct 9 at 3:00 PM" */
          __('%1$s, resumed %2$s', 'yaymatik'),
          summary(step, run),
          shortDayTime(step.finishedAt),
        )
      : summary(step, run);
  },
  send_email: (step) =>
    sprintf(
      /* translators: %s: one or more email addresses, comma-separated */ __('Sent to %s', 'yaymatik'),
      text(step.output.to),
    ),
  send_internal_email: (step) =>
    sprintf(
      /* translators: %s: one or more email addresses, comma-separated */ __('Sent to %s', 'yaymatik'),
      Array.isArray(step.output.to) ? step.output.to.join(', ') : '',
    ),
  generate_coupon: (step) => text(step.output.code),
};

const placeholderText = (value: unknown) =>
  Array.isArray(value) ? value.map(String).join(', ') : typeof value === 'number' ? String(value) : text(value);

/** Another plugin's `result` line: `{{ output.<key> }}` from the step's output, `{{ config.<field> }}` from its config. */
const catalogResult = (step: RunStepRow) => {
  const line = step.stepType ? window.yayMatikMeta.automation.steps[step.stepType]?.result : undefined;
  if (!line) return null;
  // A config value reads as its option's label, as on the card.
  return fillTemplate(line, (name) => {
    const [, root, key] = /^(output|config)\.([a-z_][a-z0-9_]*)$/.exec(name) ?? [];
    if (root === 'output') return Object.hasOwn(step.output, key) ? placeholderText(step.output[key]) : '';
    if (root === 'config') return catalogLabel(step.stepType!, key, placeholderText(step.input[key]));
    return undefined;
  });
};

/** What a step did ("Yes", "Sent to a@b.com"), why it didn't, or else its card summary ("Set to Completed"). */
export function stepResult(step: RunStepRow, run: RunContext): string {
  if (step.status === 'skipped' || step.status === 'failed' || step.status === 'cancelled') return step.error ?? '';
  if (step.status !== 'running') {
    const result = step.stepType && results[step.stepType];
    if (result) return result(step, run);
    const own = step.stepType && steps[step.stepType]?.result;
    if (own) return own(step, run, summary(step, run));
    if (step.status === 'completed') {
      const line = catalogResult(step);
      if (line) return line;
    }
  }
  return summary(step, run);
}
