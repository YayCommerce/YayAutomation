import type { WorkflowTemplate } from '@yaymatik/types';

import { structureErrors } from '../../model/structure';
import { validateWorkflow } from '../../model/validation';

/**
 * Lists templates and finds one by id. Throws on a duplicate id so a copy-pasted template folder fails
 * fast. In development it also throws when a core template's graph would be rejected by the server's
 * shape checks or has issues: Use template has to give a workflow that saves and can be activated as is.
 * Other plugins' templates are checked on the server, and may need setup before Activate.
 */
export function createTemplateRegistry(core: WorkflowTemplate[], extensions: WorkflowTemplate[] = []) {
  const list = [...core, ...extensions];
  const byId = new Map<string, WorkflowTemplate>();
  for (const template of list) {
    if (byId.has(template.id)) throw new Error(`Duplicate template id "${template.id}"`);
    byId.set(template.id, template);
  }

  if (import.meta.env.DEV) {
    for (const template of core) {
      // Shape first (what Use template's save would reject), then issues (what Activate would reject);
      // the issue check assumes every step exists.
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

  return {
    templates: list,
    findTemplate: (id: string) => byId.get(id),
    /** The other templates of the same workflow, in list order. */
    versionsOf: (template: WorkflowTemplate) =>
      list.filter((other) => other.workflow === template.workflow && other.id !== template.id),
  };
}
