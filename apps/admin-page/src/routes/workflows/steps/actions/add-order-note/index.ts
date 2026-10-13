import { defineStep } from '../../core/defineStep';
import { AddOrderNoteForm } from './Form';
import { defaults, schema, type AddOrderNoteConfig } from './schema';

export const addOrderNoteStep = defineStep<AddOrderNoteConfig>({
  id: 'add_order_note',
  kind: 'action',
  schema,
  defaults,
  Form: AddOrderNoteForm,
});
