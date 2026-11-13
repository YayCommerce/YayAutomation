import type { Run, RunDetail, StepId } from '@yaymatik/types';
import { __, sprintf } from '@wordpress/i18n';

import { shortDayTime } from '@/lib/helpers/date.helper';
import { plainSummary, steps } from '../../steps';
import { optionLabel } from '../../steps/core/summary';

type RunStepRow = RunDetail['steps'][number];

const text = (value: unknown) => (typeof value === 'string' ? value : '');

/** The step's card text, from the config it ran with. */
const summary = (step: RunStepRow, run: RunContext) =>
  step.stepType && steps[step.stepType] ? plainSummary(steps[step.stepType].summary(step.input, run.triggerId)) : '';

const customFieldResult = (step: RunStepRow) =>
  sprintf(
    step.output.changed
      ? /* translators: %s: custom field name */ __('Custom field %s updated', 'yaymatik')
      : /* translators: %s: custom field name */ __('Custom field %s unchanged', 'yaymatik'),
    text(step.output.key),
  );

/** A select's label from the catalog, e.g. a role's name; a value gone since reads as itself. */
const catalogLabel = (stepId: StepId, key: string, value: string) => {
  const field = window.yayMatikMeta.automation.steps[stepId]?.fields.find((f) => f.key === key);
  return field ? optionLabel(field, value) : value;
};

type RunContext = Pick<Run, 'waitUntil' | 'triggerId'>;

type ResultText = (step: RunStepRow, run: RunContext) => string;

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
  wait_until: (step, run) => {
    if (step.status === 'waiting') {
      /* translators: %s: date and time, e.g. "Oct 9 at 3:00 PM" */
      return run.waitUntil ? sprintf(__('until %s', 'yaymatik'), shortDayTime(run.waitUntil)) : '';
    }
    return step.finishedAt
      ? sprintf(
          /* translators: %s: date and time, e.g. "Oct 9 at 3:00 PM" */ __('Waited until %s', 'yaymatik'),
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
  resend_wc_email: (step) =>
    /* translators: %s: a WooCommerce email's name, e.g. "Completed order" */
    sprintf(__('Sent %s', 'yaymatik'), catalogLabel('resend_wc_email', 'email', text(step.output.email))),
  update_order_meta: (step) => customFieldResult(step),
  update_customer_meta: (step) => customFieldResult(step),
  change_customer_role: (step) => {
    const role = catalogLabel('change_customer_role', 'role', text(step.input.role));
    if (step.output.from === step.output.to) return __('Roles unchanged', 'yaymatik');
    if (step.input.mode === 'remove')
      return /* translators: %s: role name */ sprintf(__('Removed %s', 'yaymatik'), role);
    if (step.input.mode === 'replace') {
      return /* translators: %s: role name */ sprintf(__('Replaced roles with %s', 'yaymatik'), role);
    }
    return /* translators: %s: role name */ sprintf(__('Added %s', 'yaymatik'), role);
  },
};

const placeholderText = (value: unknown) =>
  Array.isArray(value) ? value.map(String).join(', ') : typeof value === 'number' ? String(value) : text(value);

/** Another plugin's `result` line: each `%key%` from the step's output, else its config. */
const catalogResult = (step: RunStepRow) => {
  const line = step.stepType ? window.yayMatikMeta.automation.steps[step.stepType]?.result : undefined;
  if (!line) return null;
  return line.replace(/%([a-z_][a-z0-9_]*)%/g, (_, key: string) =>
    placeholderText(key in step.output ? step.output[key] : step.input[key]),
  );
};

/** What a step did ("Yes", "Sent to a@b.com"), why it didn't, or else its card summary ("Set to Completed"). */
export function stepResult(step: RunStepRow, run: RunContext): string {
  if (step.status === 'skipped' || step.status === 'failed' || step.status === 'cancelled') return step.error ?? '';
  if (step.status !== 'running') {
    const result = step.stepType && results[step.stepType];
    if (result) return result(step, run);
    if (step.status === 'completed') {
      const line = catalogResult(step);
      if (line) return line;
    }
  }
  return summary(step, run);
}
