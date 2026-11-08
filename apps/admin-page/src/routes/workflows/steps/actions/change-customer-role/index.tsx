import { __ } from '@wordpress/i18n';

import { defineStep } from '../../core/defineStep';
import { Hints } from '../../triggers/core/NoSettings';

/** Form and checks come from includes/Workflow/Actions/ChangeCustomerRole.php `fields()`; its role list leaves out protected roles. */
export const changeCustomerRoleStep = defineStep({
  id: 'change_customer_role',
  kind: 'action',
  Footer: () => (
    <Hints hints={[__('Store staff are never changed. A customer’s only role is never removed.', 'yaymatik')]} />
  ),
});
