import { __, sprintf } from '@wordpress/i18n';

import { defineStep } from '@/routes/workflows/steps/core/defineStep';
import type { Step, StepResultText } from '@/routes/workflows/steps/core/types';

/** Both custom field actions: whether the value changed. */
export const customFieldResult: StepResultText = (step) =>
  sprintf(
    step.output.changed
      ? /* translators: %s: custom field name */ __('Custom field %s updated', 'yaymatik')
      : /* translators: %s: custom field name */ __('Custom field %s unchanged', 'yaymatik'),
    String(step.output.key ?? ''),
  );

/** Form and checks come from includes/Pro/Workflow/Actions/UpdateOrderMeta.php `fields()`. */
export const updateOrderMetaStep = (): Step =>
  defineStep({ id: 'update_order_meta', kind: 'action', result: customFieldResult });
