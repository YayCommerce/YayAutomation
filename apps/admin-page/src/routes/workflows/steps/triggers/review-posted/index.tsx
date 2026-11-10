import { __ } from '@wordpress/i18n';

import { defineStep } from '../../core/defineStep';
import { NoSettings } from '../core/NoSettings';

/** From includes/Workflow/Triggers/ReviewPosted.php, which has no fields: only what to know. */
export const reviewPostedStep = defineStep({
  id: 'review_posted',
  kind: 'trigger',
  Footer: () => (
    <NoSettings
      hints={[
        __(
          'Replies and reviews waiting for approval don’t start it. Use Review › Rating in a Condition for low or high ratings.',
          'yaymatik',
        ),
        __('A guest reviewer has only an email address.', 'yaymatik'),
      ]}
    />
  ),
});
