import * as v from 'valibot';

import { messages } from '../../../model/messages';
import { statuses } from '../../triggers/order-status-changed/schema';

/** Mirrors includes/Workflow/Steps/Actions/ChangeOrderStatus.php. Slug without the `wc-` prefix. */
export type ChangeOrderStatusConfig = { status: string };

const chooseStatus = messages.chooseNewStatus;

export const schema = v.object({
  status: v.pipe(
    v.string(),
    v.check(
      // Without WooCommerce there's nothing to check against; the PHP step skips the check too.
      (status) => status !== '' && (statuses.length === 0 || statuses.some((s) => s.value === status)),
      chooseStatus,
    ),
  ),
});

export const defaults = (): ChangeOrderStatusConfig => ({ status: '' });
