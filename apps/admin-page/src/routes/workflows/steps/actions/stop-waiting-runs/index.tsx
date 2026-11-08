import { __ } from '@wordpress/i18n';

import { defineStep } from '../../core/defineStep';
import { Hints } from '../../triggers/core/NoSettings';

/** Form and checks come from includes/Workflow/Actions/StopWaitingRuns.php `fields()`; a deleted workflow is an issue after a save. */
export const stopWaitingRunsStep = defineStep({
  id: 'stop_waiting_runs',
  kind: 'action',
  Footer: () => (
    <Hints
      hints={[
        __(
          'Stops runs of these workflows that are waiting for this customer: their orders, carts and account. Runs already moving aren’t stopped.',
          'yaymatik',
        ),
      ]}
    />
  ),
});
