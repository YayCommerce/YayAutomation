/** `@edition`: what the edition adds to core, from `src/lite/` or `src/pro/` (Vite alias, picked by YAYMATIK_EDITION). */
declare module '@edition' {
  import type { WorkflowTemplate } from '@yaymatik/types';

  import type { Step } from '@/routes/workflows/steps/core/types';

  export const steps: Step[];
  export const templates: WorkflowTemplate[];
}
