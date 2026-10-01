import type { WorkflowTemplate } from '@yay-automation/types';

import { structureErrors } from '../../model/structure';
import { validateWorkflow } from '../../model/validation';

/**
 * Lists templates and finds one by id. Throws on a duplicate id so a copy-pasted template folder fails
 * fast. In development it also throws when a template's graph would be rejected by the server's
 * shape checks or has issues: Install has to give a workflow that saves and can be turned on as is.
 */
export function createTemplateRegistry(list: WorkflowTemplate[]) {
  const byId = new Map<string, WorkflowTemplate>();
  for (const template of list) {
    if (byId.has(template.id)) throw new Error(`Duplicate template id "${template.id}"`);
    byId.set(template.id, template);
  }

  if (import.meta.env.DEV) {
    for (const template of list) {
      // Shape first (what Install's save would reject), then issues (what Turn on would reject);
      // the issue check assumes every block exists.
      const broken = structureErrors(template.graph);
      const problems = broken.length
        ? broken
        : validateWorkflow({ name: template.name, graph: template.graph }).map(
            (issue) => `${issue.code} (${issue.message})`,
          );
      if (problems.length) {
        throw new Error(`Template "${template.id}" has issues: ${problems.join('; ')}`);
      }
    }
  }

  return { templates: list, findTemplate: (id: string) => byId.get(id) };
}
