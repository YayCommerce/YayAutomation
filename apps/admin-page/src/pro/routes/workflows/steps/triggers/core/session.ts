import { defineStep } from '@/routes/workflows/steps/core/defineStep';
import type { Config, Step } from '@/routes/workflows/steps/core/types';

/**
 * A login trigger: form and checks come from includes/Pro/Workflow/Triggers/CustomerSession.php `fields()`.
 * Days are whole, like its `sanitize()`; a blank stays null, so the form asks for a number.
 */
export const sessionStep = (id: 'user_logged_in' | 'customer_logged_in'): Step => {
  const step: Step = defineStep({
    id,
    kind: 'trigger',
    normalize: (config: Config) => {
      const defaults = step.defaults();
      const merged = { ...defaults, ...Object.fromEntries(Object.entries(config).filter(([key]) => key in defaults)) };
      return { ...merged, days: typeof merged.days === 'number' ? Math.round(merged.days) : merged.days };
    },
  });
  return step;
};
