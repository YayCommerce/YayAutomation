import { defineStep } from '../../core/defineStep';

/** Form and checks come from includes/Workflow/Actions/AddOrderNote.php `fields()`. */
export const addOrderNoteStep = defineStep({ id: 'add_order_note', kind: 'action' });
