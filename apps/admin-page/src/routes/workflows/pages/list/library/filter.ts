import type { WorkflowPlugin } from '@yay-automation/types';

/** Plugin filter: with several chosen, a template shows when it uses any of them (OR). None chosen → all show. */
export const usesAnyOf = (used: WorkflowPlugin[], chosen: WorkflowPlugin[]) =>
  !chosen.length || used.some((plugin) => chosen.includes(plugin));
