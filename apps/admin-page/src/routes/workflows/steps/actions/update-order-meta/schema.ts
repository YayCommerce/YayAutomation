import * as v from 'valibot';

import { required, withKnownVariables } from '../../core/fields/schema';

/** Mirrors includes/Workflow/Steps/Actions/UpdateOrderMeta.php. An empty value is stored as is. */
export type UpdateOrderMetaConfig = { key: string; value: string };

export const schema = withKnownVariables(
  v.object({
    key: required(),
    value: v.string(),
  }),
  ['value'],
);

export const defaults = (): UpdateOrderMetaConfig => ({ key: '', value: '' });
