import * as v from 'valibot';

import { unknownVariable } from '../data';

/** A string that isn't blank. */
export const required = (message: string) =>
  v.pipe(
    v.string(),
    v.check((value) => value.trim() !== '', message),
  );

/**
 * Wraps an object schema with the unknown-merge-tag check on the given text fields. Runs only once
 * the fields themselves are valid, like PHP's `validate()` checks them last.
 */
export const withKnownVariables = <T extends v.GenericSchema<Record<string, unknown>>>(
  schema: T,
  fields: (keyof v.InferOutput<T>)[],
) =>
  v.pipe(
    schema,
    v.rawCheck(({ dataset, addIssue }) => {
      if (!dataset.typed) return;
      const error = unknownVariable(...fields.map((key) => String(dataset.value[key])));
      if (error) addIssue({ message: error });
    }),
  );
