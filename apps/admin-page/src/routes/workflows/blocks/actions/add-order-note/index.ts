import { __, sprintf } from '@wordpress/i18n';

import { defineBlock, value } from '../../core/defineBlock';
import { AddOrderNoteForm } from './Form';
import { defaults, schema, type AddOrderNoteConfig } from './schema';

export const addOrderNoteBlock = defineBlock<AddOrderNoteConfig>({
  id: 'add_order_note',
  kind: 'action',
  label: __('Add order note', 'yay-automation'),
  description: __('Add a private note to the order, or a note the customer gets by email.', 'yay-automation'),
  schema,
  defaults,
  summary: ({ note, type }) =>
    sprintf(
      type === 'customer'
        ? /* translators: %s: note text */ __('Add a note to the customer: %s', 'yay-automation')
        : /* translators: %s: note text */ __('Add a private note: %s', 'yay-automation'),
      value(note),
    ),
  Form: AddOrderNoteForm,
});
