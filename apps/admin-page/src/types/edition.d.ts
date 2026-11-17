// `@edition/*`: what the edition adds to core, from `src/lite/` or `src/pro/` (Vite alias, picked by YAYMATIK_EDITION).
// One module each: the step registry must not import templates, which import the registry.

declare module '@edition/steps' {
  import type { Step } from '@/routes/workflows/steps/core/types';

  export const steps: Step[];
}

declare module '@edition/templates' {
  import type { WorkflowTemplate } from '@yaymatik/types';

  export const templates: WorkflowTemplate[];
}

declare module '@edition/criteria' {
  /** What the condition form offers beside a flat list of rules with fixed values; custom field rules come with their data fields. */
  export const criteriaFeatures: { groups: boolean; variables: boolean };
}

declare module '@edition/settings' {
  /** What a save may set, [ min, max ]; null: not shown. */
  export const settingRanges: {
    abandonMinutes: [number, number] | null;
    cartRetentionDays: [number, number];
    runRetentionDays: [number, number];
  };
}

declare module '@edition/teaser' {
  import type { ComponentType } from 'react';

  /** The free edition's "PRO" group; null in YayMatik Pro. */
  export const ProTeaser: ComponentType<{ place: 'library' | 'trigger' | 'action' }> | null;
}

declare module '@edition/health' {
  /** Health check rows of the daily jobs the edition adds, by check id (`<name>_job`). */
  export const healthJobs: Record<string, { title: () => string; help: () => string; fixed: () => string }>;
}
