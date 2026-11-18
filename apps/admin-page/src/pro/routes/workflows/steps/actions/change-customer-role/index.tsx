import { __, sprintf } from '@wordpress/i18n';

import { defineStep } from '@/routes/workflows/steps/core/defineStep';
import { catalogLabel } from '@/routes/workflows/steps/core/summary';
import type { Step } from '@/routes/workflows/steps/core/types';
import { Hints } from '@/routes/workflows/steps/triggers/core/NoSettings';

/** Form and checks come from includes/Pro/Workflow/Actions/ChangeCustomerRole.php `fields()`; its role list leaves out protected roles. */
export const changeCustomerRoleStep = (): Step =>
  defineStep({
    id: 'change_customer_role',
    kind: 'action',
    result: (step) => {
      const role = catalogLabel('change_customer_role', 'role', String(step.input.role ?? ''));
      if (step.output.from === step.output.to) return __('Roles unchanged', 'yaymatik');
      if (step.input.mode === 'remove')
        return /* translators: %s: role name */ sprintf(__('Removed %s', 'yaymatik'), role);
      if (step.input.mode === 'replace') {
        return /* translators: %s: role name */ sprintf(__('Replaced roles with %s', 'yaymatik'), role);
      }
      return /* translators: %s: role name */ sprintf(__('Added %s', 'yaymatik'), role);
    },
    Footer: () => (
      <Hints hints={[__('Store staff are never changed. A customer’s only role is never removed.', 'yaymatik')]} />
    ),
  });
