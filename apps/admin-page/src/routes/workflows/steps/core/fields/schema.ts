import type { StepId } from '@yaymatik/types';
import * as v from 'valibot';
import { sprintf } from '@wordpress/i18n';

import { messages } from '../../../model/messages';
import { tagError } from '../data';

/** A string that isn't blank. Generic message, for steps PHP doesn't have yet. */
export const required = () =>
  v.pipe(
    v.string(),
    v.check((value) => value.trim() !== '', messages.fieldRequired),
  );

/**
 * A string that isn't blank, for a step PHP has: `message` is the same string as the PHP field's
 * `required( … )` (e.g. "%s needs a note."), filled with the step's label from the catalog.
 */
export const requiredFor = (stepId: StepId, message: string) =>
  v.pipe(
    v.string(),
    v.check(
      (value) => value.trim() !== '',
      // `message` holds one %s; the cast tells sprintf's types to expect one argument.
      sprintf(message as '%s', window.yayMatikMeta.automation.steps[stepId]?.label ?? stepId),
    ),
  );

/**
 * Wraps an object schema with the merge-tag check on the given text fields (`tagError()`: malformed
 * tags, unknown filters and paths). Runs only once the fields themselves are valid, like PHP checks
 * tags after `validate()`. `allowPending`: for steps still coming soon, which may use fields PHP
 * doesn't have yet (see `pending.ts`).
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
      const error = tagError(texts, { allowPending });
      if (error) addIssue({ message: error });
    }),
  );
