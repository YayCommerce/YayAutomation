import { __ } from '@wordpress/i18n';

import { defineStep } from '../../core/defineStep';
import { Hints } from '../../triggers/core/NoSettings';

/** Form and checks come from includes/Workflow/Actions/UpdateCustomerMeta.php `fields()`; only PHP knows the protected names. */
export const updateCustomerMetaStep = defineStep({
  id: 'update_customer_meta',
  kind: 'action',
  Footer: () => <Hints hints={[__('Customers without an account are skipped.', 'yaymatik')]} />,
});
