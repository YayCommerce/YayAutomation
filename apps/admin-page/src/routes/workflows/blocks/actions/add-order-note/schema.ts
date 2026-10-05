import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import { required, withKnownVariables } from '../../core/fields/schema';

/** Mirrors includes/Engine/Blocks/Actions/AddOrderNote.php. A customer note is also emailed to the customer. */
export type AddOrderNoteConfig = { note: string; type: 'private' | 'customer' };

export const schema = withKnownVariables(
  v.object({
    note: required(__('Enter a note.', 'yay-automation')),
    type: v.picklist(['private', 'customer']),
  }),
  ['note'],
);

export const defaults = (): AddOrderNoteConfig => ({ note: '', type: 'private' });
