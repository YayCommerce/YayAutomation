import type { WorkflowGraph, WorkflowPlugin } from '@yaymatik/types';

import { stepOf } from '../../steps';

/** Plugins a graph's steps come from, in step order: what a template needs installed. */
export function pluginsOf(graph: WorkflowGraph): WorkflowPlugin[] {
  const plugins = graph.nodes.map((node) => stepOf(node.data)?.plugin).filter((plugin) => plugin !== undefined);
  return [...new Set(plugins)];
}
