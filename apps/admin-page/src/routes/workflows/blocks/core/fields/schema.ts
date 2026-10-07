import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import { unknownVariable, unknownVariableAllowingPending } from '../data';

/** A string that isn't blank. Same message as PHP `Block::required()`, like Shopify Flow's. */
export const required = () =>
  v.pipe(
    v.string(),
    v.check((value) => value.trim() !== '', __('This field is required', 'yay-automation')),
  );

/**
 * Wraps an object schema with the unknown-merge-tag check on the given text fields. Runs only once
 * the fields themselves are valid, like PHP's `validate()` checks them last. `allowPending`: for
 * blocks still coming soon, which may use fields PHP doesn't have yet (see `pending.ts`).
 */
export const withKnownVariables = <T extends v.GenericSchema<Record<string, unknown>>>(
  schema: T,
  fields: (keyof v.InferOutput<T>)[],
  { allowPending = false } = {},
) =>
  v.pipe(
    schema,
    v.rawCheck(({ dataset, addIssue }) => {
      if (!dataset.typed) return;
      const texts = fields.map((key) => String(dataset.value[key]));
      const error = allowPending ? unknownVariableAllowingPending(...texts) : unknownVariable(...texts);
      if (error) addIssue({ message: error });
    }),
  );
