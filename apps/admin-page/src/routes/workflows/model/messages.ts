import { __ } from '@wordpress/i18n';

/**
 * Messages PHP shows too, same keys and text as PHP `Definition\Messages::all()` (sent as
 * `yayAutomationMeta.automation.messages`). The parity test fails when one differs: change both.
 * Placeholders stay in; fill them with `sprintf()`.
 */
export const messages = {
  // Workflow-level.
  nameRequired: __('Give this workflow a name.', 'yay-automation'),
  noTrigger: __('Choose a trigger.', 'yay-automation'),
  noAction: __('Add at least one action.', 'yay-automation'),
  unlinked: __('Some steps aren’t connected. Connect them or remove them.', 'yay-automation'),
  noBlock: __('Choose an action.', 'yay-automation'),
  deleteActive: __('Deactivate this workflow before you delete it.', 'yay-automation'),
  /* translators: %s: data item or step, e.g. "Order" */
  missingData: __('%s data isn’t available at this step.', 'yay-automation'),
  // Merge tags.
  /* translators: %s: merge tag, e.g. {{ order.foo }} */
  unknownVariable: __('%s isn’t an available variable.', 'yay-automation'),
  /* translators: 1: filter name, e.g. shout; 2: the available filters, e.g. "default, upper" */
  unknownFilter: __('%1$s isn’t a filter. Available filters: %2$s.', 'yay-automation'),
  /* translators: %s: a merge tag as written, e.g. {{ order. }}. Keep {{ variable }} and {{ variable | filter }} as they are. */
  malformedTag: __('%s is written incorrectly. Use {{ variable }} or {{ variable | filter }}.', 'yay-automation'),
  // Step settings.
  fieldRequired: __('This field is required.', 'yay-automation'),
  /* translators: %s: the text entered as an email address */
  invalidEmail: __('%s isn’t a valid email address.', 'yay-automation'),
  chooseOption: __('Choose an option from the list.', 'yay-automation'),
  chooseOptions: __('Choose options from the list.', 'yay-automation'),
  /* translators: %s: smallest number allowed */
  numberMin: __('Enter %s or more.', 'yay-automation'),
  /* translators: %s: largest number allowed */
  numberMax: __('Enter %s or less.', 'yay-automation'),
  // Condition rules.
  rulesRequired: __('Add a rule, or remove this condition.', 'yay-automation'),
  ruleVariable: __('Choose data for each rule.', 'yay-automation'),
  ruleComparison: __('Choose a comparison for each rule.', 'yay-automation'),
  ruleCompareWith: __('Choose data to compare with.', 'yay-automation'),
  /* translators: %s: variable, e.g. "Order total" */
  ruleValueRequired: __('Enter a value for %s.', 'yay-automation'),
  /* translators: %s: comparison name, "is empty" */
  ruleBlankHint: __('Or choose “%s” to check for a blank value.', 'yay-automation'),
  ruleNumber: __('Enter a number.', 'yay-automation'),
  ruleDate: __('Enter a date (YYYY-MM-DD).', 'yay-automation'),
  ruleOption: __('Choose a value from the list.', 'yay-automation'),
};

export type MessageKey = keyof typeof messages;
