import { Clock } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { defineStep } from '../../core/defineStep';
import { Hint } from '../core/CartTriggerNotes';

const DEFAULT_DAYS = 90;

/** Form and checks come from includes/Workflow/Triggers/CustomerInactive.php `fields()`. */
export const customerInactiveStep = defineStep({
  id: 'customer_inactive',
  kind: 'trigger',
  // Mirrors the PHP step's own `sanitize()`: whole days. Blank is null and stays, so the form shows it as required.
  normalize: ({ days = DEFAULT_DAYS }) => ({ days: typeof days === 'number' ? Math.round(days) : days }),
  Footer: () => <Hint icon={<Clock aria-hidden />}>{__('Checked once a day', 'yaymatik')}</Hint>,
});
