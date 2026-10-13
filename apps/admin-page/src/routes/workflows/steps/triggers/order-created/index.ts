import * as v from 'valibot';

import { defineStep } from '../../core/defineStep';

/** Mirrors includes/Workflow/Steps/Triggers/OrderCreated.php. Nothing to configure. */
export const orderCreatedStep = defineStep<Record<string, never>>({
  id: 'order_created',
  kind: 'trigger',
  schema: v.object({}),
  defaults: () => ({}),
  normalize: () => ({}),
});
