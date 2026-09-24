# Plan: Workflow canvas demo

## Goal
Demo workflow canvas on the editor route: TanStack Query loads a mock workflow, TanStack Form holds the editable draft, ReactFlow renders it. No Zustand.

Starting point for the team — minimal, readable example code. No speculative business rules.

## Approach
- **State ownership**
  - Server state (fetch/save) → TanStack Query.
  - Editable draft (name, nodes, edges, positions, dirty) → TanStack Form.
  - View-only (selection, measured size) → ReactFlow changes in local state, merged at render. Keeps selection out of dirty.
  - Zustand not needed: Form's store + selectors + context cover it.
- **Deps:** `pnpm -F @yay-automation/admin add @xyflow/react`.
- **Types:** `packages/types/src/workflow.ts` — `WorkflowNodeKind`, `WorkflowNode`, `WorkflowEdge` (`then | otherwise` handle on conditions), `WorkflowGraph`, `Workflow`.
- **Mock API:** `routes/workflows/editor/workflow.mock.ts` — in-memory `getWorkflow` / `saveWorkflow` with fake delay.
- **Query:** `routes/workflows/editor/workflow.query.ts` — `useWorkflowQuery(id)`, `useSaveWorkflowMutation()`.
- **Form:** `useForm({ defaultValues: workflow })`, submit → mutation → reset. `addNode` / `removeNode` via `setFieldValue('graph', …)`. Context exposes them to custom nodes.
- **Canvas:** `WorkflowCanvas` maps form graph → ReactFlow nodes. Node card, `+` dropdown per source handle, trash on hover.
- **Page:** skeleton while loading, editor keyed by id, Save button disabled when not dirty.

## Checkpoints
1. Canvas — Risk: 1 — dep, types, mock API, query hook, node components; read-only render. Test: `#/workflows/demo` → skeleton, then 4 nodes with Then/Otherwise edges; pan/zoom; light/dark.
2. Form-backed DAG state — Risk: 2 — form, context, add/remove/drag, Save via mutation. Test: add nodes on branches, delete node (trigger locked), drag → Save enabled, select → not, Save → toast, persists across list ↔ editor.

## Out of Scope
Real REST/PHP persistence, config panel, validation rules, undo/redo, auto-layout, manual edge drawing, route-leave guard, list page.

## Meta
- Target branch: feature/workflow-canvas
- Base branch: main
