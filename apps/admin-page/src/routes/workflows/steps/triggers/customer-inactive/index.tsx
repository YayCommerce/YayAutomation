import { Clock } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { defineStep } from '../../core/defineStep';
import type { Step } from '../../core/types';
import { Hint } from '../core/CartTriggerNotes';

/** Form and checks come from includes/Workflow/Triggers/CustomerInactive.php `fields()`. */
export const customerInactiveStep: Step = defineStep({
  id: 'customer_inactive',
  kind: 'trigger',
  // Whole days, like the PHP step's own `sanitize()`. Blank is null: it stays, so the form asks for a number before PHP's default would fill it.
  normalize: ({ days = customerInactiveStep.defaults().days }) => ({
    days: typeof days === 'number' ? Math.round(days) : days,
  }),
  Footer: () => <Hint icon={<Clock aria-hidden />}>{__('Checked once a day', 'yaymatik')}</Hint>,
});
