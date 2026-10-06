import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import { statuses } from '../../triggers/order-status-changed/schema';

/** Mirrors includes/Engine/Blocks/Actions/ChangeOrderStatus.php. Slug without the `wc-` prefix. */
export type ChangeOrderStatusConfig = { status: string };

const chooseStatus = __('Choose a status.', 'yay-automation');

export const schema = v.object({
  status: v.pipe(
    v.string(),
    v.check(
      // Without WooCommerce there's nothing to check against; the PHP block skips the check too.
      (status) => status !== '' && (statuses.length === 0 || statuses.some((s) => s.value === status)),
      chooseStatus,
    ),
  ),
});

export const defaults = (): ChangeOrderStatusConfig => ({ status: '' });
