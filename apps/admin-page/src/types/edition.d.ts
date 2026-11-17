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

declare module '@edition/health' {
  /** Health check rows of the daily jobs the edition adds, by check id (`<name>_job`). */
  export const healthJobs: Record<string, { title: () => string; help: () => string; fixed: () => string }>;
}
