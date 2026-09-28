# Plan: Workflows UI — empty state, template library, template preview

## Goal
`#/workflows` empty state, template library modal (`?templates=1`), read-only template preview page, and "Use template" → draft workflow in the existing editor.

## Approach
- **Types:** `packages/types/src/workflow.ts` — `WorkflowTemplate { id, name, description, category, apps[], popularity, createdAt, graph }`. Reuses `WorkflowGraph`.
- **Mock data:** `routes/workflows/templates/template.mock.ts` — 8 WooCommerce templates (abandoned cart, VIP tagging, low-stock alert, review request, win-back, first-order thank-you, …). `getTemplates()` / `getTemplate(id)` with fake delay, same pattern as `workflow.mock.ts`.
- **Workflow mock:** add `createWorkflow({ name, graph })` to `workflow.mock.ts` (id generated like `addNode`). `useCreateWorkflowMutation()` in `workflow.query.ts`, seeds the detail cache so the editor opens instantly.
- **Query:** `template.query.ts` — `useTemplatesQuery()`, `useTemplateQuery(id)`.
- **List page** (`WorkflowListPage`), matching Image 1:
  - Header row: "Workflows" left. Right: Browse templates, Import (disabled, wrapped in a span so the "Coming soon" tooltip shows), Create workflow.
  - Centered `Empty` state with an inline SVG illustration in theme tokens.
  - "Create workflow" creates a blank draft (one placeholder trigger) and opens the editor. Replaces the `/workflows/new` link, which currently errors.
- **Templates modal** (`TemplatesDialog`), matching Image 2:
  - Open state via `useSearchParams` → `?templates=1`.
  - Wide `Dialog`: hero banner (title, subtitle, inline SVG), `InputGroup` search, Sort `Select` (Most popular, Newest, Name A–Z).
  - Responsive 1/2/3-col card grid: name, 3-line clamped description, category, app icons (lucide).
  - Search over name, description, category. Search and sort are local state. Empty-results state.
  - Card click → `/workflows/templates/:templateId`.
- **Read-only canvas:** split `WorkflowNodeCard` into a presentational `NodeCardView` (card + handles, no form context) and the editable wrapper (delete, `+` menu). New `ReadOnlyCanvas`: ReactFlow with view-only `nodeTypes`, same background, controls and theme. No drag, select or connect.
- **Preview page** (`TemplatePreviewPage`):
  - Uses `EditorLayout` with a new `backTo` prop → `/workflows?templates=1` so the modal reopens.
  - Toolbar: name, "Template" badge, "Use template" on the right with pending state.
  - Body: canvas left, `w-80` details panel right (description, category, apps, step list).
  - Loading skeleton and not-found state.
  - "Use template" → `createWorkflow` with the template name and a cloned graph → `/workflows/:id`.
- **Router:** add `workflows/templates/:templateId`. The static segment outranks `:workflowId`.

## Checkpoints
1. Workflows UI — Risk: 2 — everything above. Test:
   - `#/workflows` matches Image 1; Import disabled with "Coming soon" tooltip.
   - Browse templates sets `?templates=1`; reload keeps the modal open.
   - Search filters, sort reorders, nonsense query shows empty results.
   - Card → preview with read-only canvas (no drag, `+` or delete) and side panel.
   - Back reopens the modal.
   - Use template → editor with a new draft; Save works.
   - Create workflow → blank draft.
   - Light and dark mode. `pnpm build` passes.

## Out of Scope
Workflow list table (drafts not listed yet), Import, real triggers/actions, REST/PHP persistence, execution, category or app filters in the modal, search/sort in the URL, editing in the preview, new dependencies.

## Meta
- Target branch: jack/workflows-templates
- Base branch: main
