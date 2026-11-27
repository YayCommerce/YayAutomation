import type { StepId, WorkflowPlugin } from '@yaymatik/types';
import { __, sprintf } from '@wordpress/i18n';

import type { Step } from './types';

/** Steps of plugins that aren't active now, from PHP's step index: drawn greyed and read-only, kept on save. */
export function inactiveSteps(): Record<string, Step> {
  return Object.fromEntries(
    Object.entries(window.yayMatikMeta.automation.inactiveSteps).map(([id, meta]): [string, Step] => [
      id,
      {
        id: id as StepId,
        kind: meta.kind,
        label: meta.label,
        description: '',
        plugin: meta.module as WorkflowPlugin,
        provides: [],
        requires: [],
        outputs: meta.outputs.map((output) => ({ ...output, type: 'string' })),
        defaults: () => ({}),
        normalize: (config) => config,
        firstIssue: () => null,
        firstError: () => null,
        tags: () => [],
        drawsField: () => false,
        uses: () => [],
        /* translators: %s: plugin name, e.g. "YayMail" */
        summary: () =>
          sprintf(__('Needs %s — the plugin isn’t active.', 'yaymatik-automation-for-woocommerce'), meta.plugin),
        inactive: meta.plugin,
      },
    ]),
  );
}
