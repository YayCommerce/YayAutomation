import type { WorkflowTemplate } from '@yay-automation/types';

import type { TemplateSpec } from './types';

/**
 * Declares one template (its folder's `index.ts` default export). Static: Use template creates an inactive
 * draft from a copy of the graph, so nothing here touches the server.
 */
export function defineTemplate(spec: TemplateSpec): WorkflowTemplate {
  return spec;
}
