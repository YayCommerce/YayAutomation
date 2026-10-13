import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import { requiredFor, withKnownVariables } from '../../core/fields/schema';

/** Mirrors includes/Workflow/Steps/Actions/UpdateOrderMeta.php. An empty value is stored as is. */
export type UpdateOrderMetaConfig = { key: string; value: string };

export const schema = withKnownVariables(
  v.object({
    /* translators: %s: step name, e.g. "Update order custom field" */
    key: requiredFor('update_order_meta', __('%s needs a field name.', 'yay-automation')),
    value: v.string(),
  }),
  ['value'],
);

export const defaults = (): UpdateOrderMetaConfig => ({ key: '', value: '' });
