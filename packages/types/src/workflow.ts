/**
 * Workflow document shape. Library-agnostic: the canvas maps it to ReactFlow nodes/edges.
 */

export type WorkflowNodeKind = 'trigger' | 'condition' | 'action';

/** Outgoing branch of a condition node. Used as the edge `sourceHandle`. */
export type WorkflowBranch = 'then' | 'otherwise';

/**
 * Step type. Each id has a TS folder (admin app `steps/<kind>/<id-in-kebab-case>/`) and a PHP class
 * (`includes/Workflow/Steps/<Kind>/`).
 */
export type StepId =
  | 'order_created'
  | 'order_status_changed'
  | 'condition'
  | 'send_email'
  | 'send_internal_email'
  | 'generate_coupon'
  | 'wait'
  | 'change_order_status'
  | 'add_order_note'
  | 'update_order_meta';

/** `type` (not `interface`) so it satisfies ReactFlow's `Record<string, unknown>` data constraint. */
export type WorkflowNodeData = {
  /** Null on a trigger or action step whose block isn't chosen yet. */
  block: StepId | null;
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
  /** Draft changes of an active workflow; null when there are none (always null while inactive). */
  draft: WorkflowGraph | null;
  /** ISO date of the last change to the live graph, status or name. Draft saves don't move it. */
  updatedAt: string;
  /** ISO date of the last draft save; null without a draft. */
  draftUpdatedAt: string | null;
  /** Activated at least once. Inactive + false = "Draft" (never activated); inactive + true = "Inactive". */
  everActivated: boolean;
  /** ISO date the workflow was last activated; null while inactive. */
  activatedAt: string | null;
  /** ISO date of the newest version (draft saves included): "Last updated". */
  lastVersionAt: string;
  /** What blocks Activate / Update, found by the server: the live graph's, and the draft's (null without one). */
  issues: { live: WorkflowIssue[]; draft: WorkflowIssue[] | null };
}

export type WorkflowVersionAction = 'created' | 'edited' | 'activated' | 'deactivated';

/** One entry of a workflow's version history. */
export interface WorkflowVersion {
  id: string;
  /** Last thing done to this version. */
  action: WorkflowVersionAction;
  /** Display name of who did it; null when that user has been deleted. */
  user: string | null;
  /** ISO date of that action. */
  createdAt: string;
  graph: WorkflowGraph;
  /** `draft`: the draft changes, or the content of a never-activated workflow. `active`: running now. */
  status: 'draft' | 'active' | null;
}

/** A page of `GET /workflows/:id/versions`, newest first. */
export interface WorkflowVersionsPage {
  items: WorkflowVersion[];
  hasMore: boolean;
}

/** Plugin that triggers and actions are grouped under (PHP `Workflow\Extend\Plugins`). */
export type WorkflowPlugin = 'woocommerce' | 'yayautomation';

/** Library chip a template is filed under. Labels live in the admin app. */
export type TemplateCategory = 'recover_carts' | 'post_purchase' | 'customer_lifecycle' | 'alerts';

/** Pre-built workflow shown in the template library. Use template creates an inactive draft from `graph`. */
export interface WorkflowTemplate {
  id: string;
  name: string;
  description: string;
  category: TemplateCategory;
  /** How often it was used. Sort key for "Most popular". */
  popularity: number;
  /** ISO date. Sort key for "Newest". */
  createdAt: string;
  graph: WorkflowGraph;
}

/** Codes shared by the TS `validateWorkflow()` and PHP `Validator::errors()`. */
export type WorkflowIssueCode =
  | 'name_required'
  | 'no_trigger'
  | 'no_action'
  | 'unlinked'
  | 'no_block'
  | 'invalid_config'
  | 'missing_data';

/** Something that blocks Activate or Update. `nodeId` is set when the issue belongs to one step. */
export interface WorkflowIssue {
  code: WorkflowIssueCode;
  message: string;
  nodeId?: string;
  /** `invalid_config`: the step's config key the issue is about, when PHP knows it. */
  field?: string;
  /** `unlinked` only: every step not connected to the trigger. Workflow-level, so no `nodeId`. */
  nodeIds?: string[];
}

export type RunStatus = 'queued' | 'running' | 'waiting' | 'completed' | 'failed' | 'cancelled' | 'skipped';

/** One run of a workflow, as `GET /workflows/{id}/runs` lists it. Dates are ISO 8601 in UTC. */
export interface Run {
  id: string;
  workflowId: string;
  /** Version the run pinned when it started; it follows that graph to the end. */
  versionId: string;
  triggerId: string;
  /** What the run is about, e.g. `order:12`. */
  subject: string;
  status: RunStatus;
  /** Why it failed, was skipped or was cancelled; null otherwise. */
  error: string | null;
  waitUntil: string | null;
  /** Run whose step caused this one; null when an event started it. */
  parentRunId: string | null;
  rootRunId: string;
  depth: number;
  startedAt: string | null;
  finishedAt: string | null;
}

export type RunStepStatus = 'running' | 'completed' | 'skipped' | 'failed' | 'waiting';

/** One step a run ran. */
export interface RunStep {
  id: string;
  runId: string;
  /** Node id in the pinned version's graph. */
  stepId: string;
  iteration: number;
  status: RunStepStatus;
  /** Step config with merge tags unrendered. */
  input: Record<string, unknown>;
  /** The step's declared outputs. */
  output: Record<string, unknown>;
  error: string | null;
  attempt: number;
  startedAt: string | null;
  finishedAt: string | null;
}

/** `GET /runs/{id}`. */
export interface RunDetail extends Run {
  steps: RunStep[];
}
