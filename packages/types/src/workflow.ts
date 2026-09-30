/**
 * Workflow document shape. Library-agnostic: the canvas maps it to ReactFlow nodes/edges.
 */

export type WorkflowNodeKind = 'trigger' | 'condition' | 'action';

/** Outgoing branch of a condition node. Used as the edge `sourceHandle`. */
export type WorkflowBranch = 'then' | 'otherwise';

/**
 * Step type. Each id has a TS folder (admin app `blocks/<kind>/<id-in-kebab-case>/`) and a PHP class
 * (`src/Engine/Blocks/<Kind>/`).
 */
export type BlockId = 'order_status_changed' | 'order_total' | 'send_admin_email';

/** `type` (not `interface`) so it satisfies ReactFlow's `Record<string, unknown>` data constraint. */
export type WorkflowNodeData = {
  title: string;
  block: BlockId;
  /** Block-specific settings. Validated by the block's schema; may be incomplete while inactive. */
  config: Record<string, unknown>;
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

export type WorkflowStatus = 'inactive' | 'active';

/** A saved workflow, as returned by the REST API. */
export interface Workflow {
  id: string;
  name: string;
  status: WorkflowStatus;
  /** Live graph: the one that runs. */
  graph: WorkflowGraph;
  /** Unapplied edits of an active workflow; null when there are none (always null while inactive). */
  draft: WorkflowGraph | null;
  /** ISO date of the last change to the live graph, status or name. Draft saves don't move it. */
  updatedAt: string;
  /** ISO date of the last draft save; null without a draft. */
  draftUpdatedAt: string | null;
  /** Turned on at least once. Inactive + false = "Draft" (never on); inactive + true = "Inactive". */
  everActivated: boolean;
}

/** Integration a template touches. Drives the icons on template cards. */
export type WorkflowTemplateApp = 'woocommerce' | 'email' | 'automation';

/** Pre-built workflow shown in the template library. Install creates an inactive draft from `graph`. */
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

/** Codes shared by the TS `validateWorkflow()` and PHP `WorkflowValidator::errors()`. */
export type WorkflowIssueCode = 'name_required' | 'no_action' | 'unlinked' | 'invalid_config';

/** Something that blocks turning on or applying changes. `nodeId` is set when the issue belongs to one step. */
export interface WorkflowIssue {
  code: WorkflowIssueCode;
  message: string;
  nodeId?: string;
  /** `unlinked` only: every step not connected to the trigger. Workflow-level, so no `nodeId`. */
  nodeIds?: string[];
}
