import type { WorkflowGraph, WorkflowPlugin } from '@yay-automation/types';

import { blockOf } from '../../blocks';

/** Plugins a graph's steps come from, in step order: what a template needs installed. */
export function pluginsOf(graph: WorkflowGraph): WorkflowPlugin[] {
  const plugins = graph.nodes.map((node) => blockOf(node.data)?.plugin).filter((plugin) => plugin !== undefined);
  return [...new Set(plugins)];
}
