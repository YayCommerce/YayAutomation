# Plan: Template — High-value order alert

## Goal

Template Library → preview → Use template → configure → activate. When a WooCommerce order moves to Processing with total above X, email the admin. Workflows persist in a private CPT via REST; mocks are removed.

## Approach

- **Types** (`packages/types/src/workflow.ts`):
  - `WorkflowNodeData` → `{ title, block: BlockId, config: Record<string, unknown> }`.
  - `BlockId = 'order_status_changed' | 'order_total' | 'send_admin_email'`.
  - `WorkflowStatus` → `'inactive' | 'active'`.
  - `Workflow.id` optional (absent on an unsaved draft); add `updatedAt`.
- **Blocks (TS)** — `routes/workflows/blocks/`, one file per block:
  - Each exports `{ id, kind, label, schema, defaults, summary(config), Form }`. Registry maps `kind` → default block.
  - `isConfigured(node)` = `v.safeParse(schema, config).success`. Single source for the node badge, the Active switch, and the form.
  - Forms: TanStack Form with the block's Valibot schema as `validators.onChange` (Standard Schema, no adapter). Kit `Field*`, `Select`, `Input`, `NumberInput`, `Textarea`. No react-hook-form, zod or formisch.
  - `order_status_changed`: `{ from: 'any' | status, to: status }`. Options from `yayAutomationMeta.wc.orderStatuses`.
  - `order_total`: `{ operator: 'gt' | 'gte' | 'lt' | 'lte', amount: number ≥ 0 }`. Currency symbol shown on the field.
  - `send_admin_email`: `{ recipients?: string, subject: string, body: string }`. Comma-separated recipients; blank = site admin email (shown as placeholder). Merge tags listed under body: `{{order_number}}`, `{{order_total}}`, `{{order_status}}`, `{{customer_name}}`, `{{customer_email}}`, `{{order_admin_url}}`, `{{site_name}}`.
- **Template** — `templates/templates.ts`:
  - The single "High-value order alert" template: trigger (any → processing) → condition (`gt`, amount empty) → Then → email with prefilled subject/body.
  - Ships unconfigured on purpose, so the user must set X.
  - Delete `template.mock.ts` and `template.query.ts`. Dialog and preview read the static list; drop the dialog's loading/error branches.
- **Drafts and routing:**
  - New `workflows/new` route, before `:workflowId`. `?template=<id>` seeds from a template; no param = blank graph with the trigger block preset. Unknown template id → not-found placeholder.
  - "Use template" and "Create workflow" only navigate. Nothing is created until Save.
  - First Save → `POST` → seed detail cache → `navigate('/workflows/:id', { replace: true })`. Later saves → `PUT`.
  - `addNode` assigns the kind's default block and its defaults.
- **Editor:**
  - Toolbar: editable workflow name (form field, `Input`), Active `Switch` (form field), Save.
  - Name required; Save disabled while empty.
  - Switch disabled while any node is unconfigured (tooltip: configure all steps first). Active + an invalid node → Save disabled (tooltip: fix the step or turn the workflow off).
  - Click a node → `Sheet` with the block form. Values sync live into the graph via `setFieldValue`.
  - Node card shows `summary(config)`, or a "Needs setup" badge.
  - Leave guard: `hooks/useRouteLeaveGuard` + `hooks/useBeforeUnloadWarning` while the form is dirty, with an `AlertDialog` for in-app navigation. The post-save redirect to `/workflows/:id` must not trigger it.
- **List page:**
  - `useWorkflowsQuery()`. Loading → skeleton rows. Zero workflows → existing empty state.
  - Otherwise a `Table`: Name (link to editor), Status badge, Trigger, Last updated, Delete row action with an `AlertDialog` confirm.
  - Delete `workflow.mock.ts`. `workflow.query.ts` switches to `api` (ky).
- **PHP:**
  - `src/Engine/Workflows/WorkflowPostType.php`: `yay_automation_wf`, `public => false`, `show_ui => false`.
  - `src/Engine/Workflows/WorkflowRepository.php`: CRUD, post ↔ array.
    - Name = `post_title`. Status = `_yay_automation_status` meta.
    - Graph = `_yay_automation_graph` meta, stored as `wp_slash( wp_json_encode() )` (`update_post_meta` unslashes).
    - `_yay_automation_trigger` meta = trigger block id, for runner lookup.
    - Delete = `wp_delete_post( $id, true )`.
  - `src/Engine/Blocks/`: `BlockRegistry` + one class per block, `validate( array $config ): true|WP_Error`, mirroring the TS schemas. Triggers `matches()`, conditions `evaluate()`, actions `run()`.
    - `send_admin_email`: strip CR/LF from subject. Validate each recipient with `is_email`; any invalid → 400.
  - Save validation:
    - Always: one trigger, known blocks, edges point at existing nodes, text sanitized.
    - `active`: every node must pass its block's `validate()`, else 400 with the failing node id.
  - REST (`RestAPI.php`, `can_manage`): `GET/POST /workflows`, `GET/PUT/DELETE /workflows/(?P<id>\d+)`.
  - Runner (`src/Engine/Workflows/Runner.php`):
    - Hooks `woocommerce_order_status_changed` only when WooCommerce is loaded.
    - Loads active workflows with a matching trigger. Walks each graph from the trigger: actions run, conditions follow the `then` / `otherwise` edge. Visited-set guard.
    - Each workflow wrapped in `try/catch (\Throwable)`. Failures and a `false` from `wp_mail` → `wc_get_logger()`, source `yay-automation`. Never rethrow.
  - Email: plain-text `wp_mail`. Total = `wc_price` → strip tags → decode entities.
  - Localize: add `wc: { active, orderStatuses, currencySymbol }` and `adminEmail` to `yayAutomationMeta`; update `localize.d.ts`.
  - HPOS: declare `custom_order_tables` compatibility on `before_woocommerce_init`.

## Checkpoints

1. Backend + engine — Risk: 3 — CPT, repository, blocks, REST CRUD, runner, email, localize, HPOS. Test:
   - REST via console `fetch`: create, get, update, list, delete (post is gone, not trashed).
   - Active with empty amount → 400; same payload inactive → 200. Invalid recipient → 400.
   - Real checkout order (COD, pending → processing) with total above X → email in the YaySMTP log.
   - Order moving to another status → no email. Total below X → no email. Workflow inactive → no email.
   - Blank recipients → email goes to `admin_email`.
   - Subject saved with newlines → stored and sent without them.
   - Forced exception in an action → order status still changes; error in the WooCommerce log.
   - `php -l` passes.
2. Frontend — Risk: 2 — types, block registry and forms, template, mock removal, `/workflows/new` flow, editable name, Sheet config, Active switch, leave guard, list table with Delete. Test:
   - Library → preview → Use template → no post created → configure condition → Active enabled → Save → URL becomes `/workflows/:id` without a leave prompt → list shows the row → Delete removes it.
   - Leaving an unsaved, edited draft creates nothing and shows the leave warning (in-app dialog and browser reload prompt).
   - Reload of `/workflows/new?template=…` and `/workflows/:id` works. Bad template id → not-found.
   - Rename in the toolbar → saved and shown in the list.
   - Field validation errors, "Needs setup" badges, disabled Active switch, blank Create workflow.
   - 002 library/preview history still correct (open, close, card → preview → back reopens the library).
   - Light and dark mode. `pnpm build` passes.

## Out of Scope

Other templates/blocks, delays, run log, dedupe, async queue, HTML email, multi-currency conversion, orders created directly in a status (`woocommerce_order_status_changed` needs a `from` status), WooCommerce-inactive notice in the UI.

## Meta

- Target branch: jack/templates/high-value-order-alert
- Base branch: main
