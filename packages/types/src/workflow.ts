/**
 * Workflow document shape. Library-agnostic: the canvas maps it to ReactFlow nodes/edges.
 */

export type WorkflowNodeKind = 'trigger' | 'condition' | 'action';

/** Outgoing branch of a condition node. Used as the edge `sourceHandle`. */
export type WorkflowBranch = 'then' | 'otherwise';

/** `type` (not `interface`) so it satisfies ReactFlow's `Record<string, unknown>` data constraint. */
export type WorkflowNodeData = {
  title: string;
  description?: string;
};

export interface WorkflowNode {
  id: string;
  kind: WorkflowNodeKind;
  position: { x: number; y: number };
  data: WorkflowNodeData;
}

export interface WorkflowEdge {
  id: string;
  source: string;
  target: string;
  /** Set only on edges leaving a condition node. */
  sourceHandle?: WorkflowBranch;
}

export interface WorkflowGraph {
  nodes: WorkflowNode[];
  edges: WorkflowEdge[];
}

export type WorkflowStatus = 'draft' | 'active';

export interface Workflow {
  id: string;
  name: string;
  status: WorkflowStatus;
  graph: WorkflowGraph;
}

/** Integration a template touches. Drives the icons on template cards. */
export type WorkflowTemplateApp = 'woocommerce' | 'email' | 'automation';

/** Pre-built workflow shown in the template library. "Use template" copies `graph` into a new draft. */
export interface WorkflowTemplate {
  id: string;
  name: string;
  description: string;
  category: string;
  apps: WorkflowTemplateApp[];
  /** Install count. Sort key for "Most popular". */
  popularity: number;
  /** ISO date. Sort key for "Newest". */
  createdAt: string;
  graph: WorkflowGraph;
}
