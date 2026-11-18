import { __ } from '@wordpress/i18n';

import { defineStep } from '@/routes/workflows/steps/core/defineStep';
import type { Step } from '@/routes/workflows/steps/core/types';
import { Hints } from '@/routes/workflows/steps/triggers/core/NoSettings';
import { customFieldResult } from '../update-order-meta';

/** Form and checks come from includes/Pro/Workflow/Actions/UpdateCustomerMeta.php `fields()`; only PHP knows the protected names. */
export const updateCustomerMetaStep = (): Step =>
  defineStep({
    id: 'update_customer_meta',
    kind: 'action',
    result: customFieldResult,
    Footer: () => <Hints hints={[__('Customers without an account are skipped.', 'yaymatik')]} />,
  });
