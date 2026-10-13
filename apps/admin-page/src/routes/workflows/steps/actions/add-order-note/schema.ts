import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import { requiredFor, withKnownVariables } from '../../core/fields/schema';

/** Mirrors includes/Workflow/Steps/Actions/AddOrderNote.php. A customer note is also emailed to the customer. */
export type AddOrderNoteConfig = { note: string; type: 'private' | 'customer' };

export const schema = withKnownVariables(
  v.object({
    /* translators: %s: step name, e.g. "Add order note" */
    note: requiredFor('add_order_note', __('%s needs a note.', 'yay-automation')),
    type: v.picklist(['private', 'customer']),
  }),
  ['note'],
);

export const defaults = (): AddOrderNoteConfig => ({ note: '', type: 'private' });
