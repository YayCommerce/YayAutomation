import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import { required, withKnownVariables } from '../../core/fields/schema';

/** Mirrors includes/Engine/Blocks/Actions/UpdateOrderMeta.php. An empty value is stored as is. */
export type UpdateOrderMetaConfig = { key: string; value: string };

export const schema = withKnownVariables(
  v.object({
    key: required(__('Enter a field name.', 'yay-automation')),
    value: v.string(),
  }),
  ['value'],
);

export const defaults = (): UpdateOrderMetaConfig => ({ key: '', value: '' });
