import { defineStep } from '../../core/defineStep';

/** Form and checks come from includes/Workflow/Triggers/OrderNoteAdded.php `fields()`. */
export const orderNoteAddedStep = defineStep({ id: 'order_note_added', kind: 'trigger' });
