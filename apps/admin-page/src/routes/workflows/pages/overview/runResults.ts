import type { Run, RunDetail, StepId } from '@yaymatik/types';
import { __, sprintf } from '@wordpress/i18n';

import { shortDayTime } from '@/lib/helpers/date.helper';
import { plainSummary, steps } from '../../steps';
import { optionLabel } from '../../steps/core/summary';

type RunStepRow = RunDetail['steps'][number];

const text = (value: unknown) => (typeof value === 'string' ? value : '');

/** The step's card text, from the config it ran with. */
const summary = (step: RunStepRow) =>
  step.stepType && steps[step.stepType] ? plainSummary(steps[step.stepType].summary(step.input)) : '';

const customFieldResult = (step: RunStepRow) =>
  sprintf(
    step.output.changed
      ? /* translators: %s: custom field name */ __('Custom field %s updated', 'yaymatik')
      : /* translators: %s: custom field name */ __('Custom field %s unchanged', 'yaymatik'),
    text(step.output.key),
  );

/** A role's name from the catalog's list; a role gone since reads as its slug. */
const roleName = (slug: string) => {
  const field = window.yayMatikMeta.automation.steps.change_customer_role?.fields.find(({ key }) => key === 'role');
  return field ? optionLabel(field, slug) : slug;
};

type ResultText = (step: RunStepRow, run: Pick<Run, 'waitUntil'>) => string;

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
          summary(step),
          shortDayTime(step.finishedAt),
        )
      : summary(step);
  },
  send_email: (step) =>
    /* translators: %s: email address */
    sprintf(__('Sent to %s', 'yaymatik'), text(step.output.to)),
  send_internal_email: (step) =>
    /* translators: %s: email addresses, comma-separated */
    sprintf(__('Sent to %s', 'yaymatik'), Array.isArray(step.output.to) ? step.output.to.join(', ') : ''),
  generate_coupon: (step) => text(step.output.code),
  update_order_meta: (step) => customFieldResult(step),
  update_customer_meta: (step) => customFieldResult(step),
  change_customer_role: (step) => {
    const role = roleName(text(step.input.role));
    if (step.output.from === step.output.to) return __('Roles unchanged', 'yaymatik');
    if (step.input.mode === 'remove')
      return /* translators: %s: role name */ sprintf(__('Removed %s', 'yaymatik'), role);
    if (step.input.mode === 'replace') {
      return /* translators: %s: role name */ sprintf(__('Replaced roles with %s', 'yaymatik'), role);
    }
    return /* translators: %s: role name */ sprintf(__('Added %s', 'yaymatik'), role);
  },
};

/** What a step did ("Yes", "Sent to a@b.com"), why it didn't, or else its card summary ("Set to Completed"). */
export function stepResult(step: RunStepRow, run: Pick<Run, 'waitUntil'>): string {
  if (step.status === 'skipped' || step.status === 'failed' || step.status === 'cancelled') return step.error ?? '';
  if (step.status !== 'running') {
    const result = step.stepType && results[step.stepType];
    if (result) return result(step, run);
  }
  return summary(step);
}
