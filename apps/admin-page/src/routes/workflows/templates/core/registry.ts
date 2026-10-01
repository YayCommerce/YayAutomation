import type { WorkflowTemplate } from '@yay-automation/types';

import { validateWorkflow } from '../../model/validation';

/**
 * Lists templates and finds one by id. Throws on a duplicate id so a copy-pasted template folder fails
 * fast. In development it also throws when a template's graph has issues: Install has to give a
 * workflow that can be turned on as is.
 */
export function createTemplateRegistry(list: WorkflowTemplate[]) {
  const byId = new Map<string, WorkflowTemplate>();
  for (const template of list) {
    if (byId.has(template.id)) throw new Error(`Duplicate template id "${template.id}"`);
    byId.set(template.id, template);
  }

  if (import.meta.env.DEV) {
    for (const template of list) {
      const issues = validateWorkflow({ name: template.name, graph: template.graph });
      if (issues.length) {
        throw new Error(
          `Template "${template.id}" has issues: ${issues.map((issue) => `${issue.code} (${issue.message})`).join('; ')}`,
        );
      }
    }
  }

  return { templates: list, findTemplate: (id: string) => byId.get(id) };
}
