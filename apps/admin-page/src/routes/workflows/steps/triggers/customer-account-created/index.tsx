import { __ } from '@wordpress/i18n';

import { defineStep } from '../../core/defineStep';

/** From includes/Workflow/Triggers/CustomerAccountCreated.php, which has no fields: nothing to set. */
export const customerAccountCreatedStep = defineStep({
  id: 'customer_account_created',
  kind: 'trigger',
  Footer: () => <p className="text-muted-foreground text-sm">{__('No settings needed.', 'yaymatik')}</p>,
});
