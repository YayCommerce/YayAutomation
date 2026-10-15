import { __ } from '@wordpress/i18n';

/**
 * Messages PHP shows too, same keys and text as PHP `Definition\Messages::all()` (sent as
 * `yayMatikMeta.automation.messages`). The parity test fails when one differs: change both.
 * Placeholders stay in; fill them with `sprintf()`.
 */
export const messages = {
  // Workflow-level.
  nameRequired: __('Give this workflow a name.', 'yaymatik'),
  noTrigger: __('Choose a trigger.', 'yaymatik'),
  noAction: __('Add at least one action.', 'yaymatik'),
  unlinked: __('Some steps aren’t connected. Connect them or remove them.', 'yaymatik'),
  noBlock: __('Choose an action.', 'yaymatik'),
  deleteActive: __('Deactivate this workflow before you delete it.', 'yaymatik'),
  /* translators: %s: data item or step, e.g. "Order" */
  missingData: __('%s data isn’t available at this step.', 'yaymatik'),
  // Merge tags.
  /* translators: %s: merge tag, e.g. {{ order.foo }} */
  unknownVariable: __('%s isn’t an available variable.', 'yaymatik'),
  /* translators: 1: filter name, e.g. shout; 2: the available filters, e.g. "default, upper" */
  unknownFilter: __('%1$s isn’t a filter. Available filters: %2$s.', 'yaymatik'),
  /* translators: %s: a merge tag as written, e.g. {{ order. }}. Keep {{ variable }} and {{ variable | filter }} as they are. */
  malformedTag: __('%s is written incorrectly. Use {{ variable }} or {{ variable | filter }}.', 'yaymatik'),
  // Step settings.
  fieldRequired: __('This field is required.', 'yaymatik'),
  /* translators: %s: the text entered as an email address */
  invalidEmail: __('%s isn’t a valid email address.', 'yaymatik'),
  chooseOption: __('Choose an option from the list.', 'yaymatik'),
  chooseOptions: __('Choose options from the list.', 'yaymatik'),
  /* translators: %s: smallest number allowed */
  numberMin: __('Enter %s or more.', 'yaymatik'),
  /* translators: %s: largest number allowed */
  numberMax: __('Enter %s or less.', 'yaymatik'),
  // Condition rules.
  rulesRequired: __('Add a rule, or remove this condition.', 'yaymatik'),
  ruleVariable: __('Choose data for each rule.', 'yaymatik'),
  ruleComparison: __('Choose a comparison for each rule.', 'yaymatik'),
  ruleCompareWith: __('Choose data to compare with.', 'yaymatik'),
  /* translators: %s: variable, e.g. "Order total" */
  ruleValueRequired: __('Enter a value for %s.', 'yaymatik'),
  /* translators: %s: comparison name, "is empty" */
  ruleBlankHint: __('Or choose “%s” to check for a blank value.', 'yaymatik'),
  ruleNumber: __('Enter a number.', 'yaymatik'),
  ruleDate: __('Enter a date (YYYY-MM-DD).', 'yaymatik'),
  ruleOption: __('Choose a value from the list.', 'yaymatik'),
};

export type MessageKey = keyof typeof messages;
