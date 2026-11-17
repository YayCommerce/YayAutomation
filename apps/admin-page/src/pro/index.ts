import type { WorkflowTemplate } from '@yaymatik/types';

import type { Step } from '@/routes/workflows/steps/core/types';

const licensed = window.yayMatikPro.licensed;

/** Pro's steps and templates, added to core's; none without a license. */
export const steps: Step[] = licensed ? [] : [];
export const templates: WorkflowTemplate[] = licensed ? [] : [];
