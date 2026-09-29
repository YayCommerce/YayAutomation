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
  - New `workflows/new` route, before `:workflowId`. `?template=<id>` seeds from a template. Unknown template id → not-found placeholder.
  - "Use template" only navigates. Nothing is created until Save.
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
   - Field validation errors, "Needs setup" badges, disabled Active switch.
   - 002 library/preview history still correct (open, close, card → preview → back reopens the library).
   - Light and dark mode. `pnpm build` passes.
3. Validation + summaries — Risk: 2 — shared workflow validation (TS + PHP), "N issues" popover, node error header and plain-language summaries, multi-status trigger, delete-with-reconnect. Keeps the current Save button and Active switch.
   - Supersedes: checkpoint 1 test 'same payload inactive → 200' (now 400), and checkpoint 2's 'Needs setup' badge and active-only validation.
   - **Visual references** (behavior follows this plan; visuals follow the images, built with YayUiKits components and theme tokens, not copied styling):
     - `.claude/references/1-automatewoo-trigger-rules.png` — status multi-select with removable tags; operator wording.
     - `.claude/references/2-shopify-node-error.png` — full-width red "Error" header above the node card.
     - `.claude/references/3-shopify-condition-summary.png` — node body as a plain-language summary.
     - `.claude/references/4-shopify-field-error.png` — required-field error under the input in the settings panel.
   - **`validateWorkflow()`**, same rules in TS (`routes/workflows/validation.ts`) and PHP (`WorkflowValidator::errors()`). Returns `{ nodeId?, code, message }[]` with shared codes: `name_required`; `no_action` ("Add an action to this workflow"); `unreachable` (node not connected to the trigger); `invalid_config` (one per node, first block-schema message). Structural checks (one trigger, known blocks, valid edges) still 400 first.
   - **Server:** every save with errors → 400 with `data.errors` (full list), active or inactive. Replaces the "inactive only needs to be well-formed" rule.
   - **Toolbar:** Save disabled whenever `validateWorkflow()` has errors. An "N issues" button next to Save opens a kit `Popover` listing them. Node errors are prefixed with the node's block label (e.g. "Order total: Enter an amount"); workflow-level errors have no prefix. Clicking a node error selects the node and opens its Sheet; `name_required` focuses the name input.
   - **Node card:** header = block label; body = `summary(config)` in plain language. `summary()` handles incomplete config with a placeholder (e.g. "Order total is greater than …") so it always reflects the current operator and statuses. Nodes with errors get a full-width red "Error" header (destructive tokens). The "Needs setup" badge is removed. Stored `data.title` stays in the data but isn't shown; the template preview's step list shows summaries.
   - **Settings panel:** field errors under each input, styled like Image 4 (icon + destructive text).
   - **Operators:** same four; labels "is greater than", "is greater than or equal to", "is less than", "is less than or equal to".
   - **`order_status_changed`** → `{ from: string[], to: string[] }`. Empty `from` = any status (shown as an "Any" placeholder); `to` needs at least one. Multi-select with tags from `ComboboxTrigger`, `ComboboxBadge`/`ComboboxRemove`, `Popover`/`Command`, `ComboboxCheckbox`. Old single strings are normalized on read (`'any'` → `[]`, `'processing'` → `['processing']`): PHP in `sanitize()` (`matches()` / `validate()` work on arrays), TS in `defineBlock`.
   - **Delete node** (button and Backspace via ReactFlow `onBeforeDelete`, one `deleteNode()`): a node with a single child reconnects that child to its parent, keeping the parent's branch handle. A condition with any children asks to confirm (AlertDialog), then removes the condition and all its descendants. A condition with no children deletes without asking. The trigger can't be deleted.
   - Unchanged: leave guard, list page, email/runner logic apart from status matching.
   - A template opened with an empty amount can't be saved until it's valid.
   - Test:
     - Template with empty amount → Save disabled, "1 issue" listed ("Order total: …"); clicking it selects the condition and opens its Sheet.
     - Delete the condition → confirm → its email branch is removed too → "Add an action to this workflow", Save disabled.
     - Delete the action only → "Add an action to this workflow".
     - Delete a middle node with one child → the child reconnects to the parent.
     - Change operator to ≤ → node summary updates live.
     - Trigger `to` = [Processing, Completed] on real orders (YaySMTP log; customer jack.yaycommerce@gmail.com): → Processing sends, → Completed sends, → On hold doesn't.
     - Workflow #1504 (single status strings) loads with Processing as a tag, saves, and still sends on a real order.
     - Server 400 via `fetch` for each case, active and inactive: empty name, no action, unreachable node (made by editing the graph JSON), invalid config; response carries `data.errors` with matching codes.
     - `/wcheck` screenshots of the error header, field error, multi-select tags and node summary, next to Images 1–4. Light and dark mode.
     - `pnpm build`, `php -l`, PHPCS pass.

4. Shopify-style drafts — Risk: 2 — drafts created up front, valid template, delete-only-this-step with unlinked steps, workflow-level "unlinked" issue.
   - Supersedes: checkpoint 3's delete rules (reconnect / cascade / confirm) and checkpoint 2's unsaved-draft flow (`/workflows/new`, first-save redirect).
   - **Visual references** (behavior follows this plan; visuals follow the images, built with YayUiKits components and theme tokens):
     - `.claude/references/5-shopify-unlinked-after-delete.png` — steps left on the canvas, unlinked from the trigger, after a delete.
     - `.claude/references/6-shopify-unlinked-condition.png` — an unlinked condition with its settings panel open.
     - `.claude/references/7-shopify-unlinked-error.png` — the "Remove or connect unlinked steps" issue.
     - Behavior deviations: no blue "Review" header on unlinked steps (Images 5–6), and issues stay in the "N issues" popover rather than a banner with "1 of N" paging (Image 7).
   - **Drafts created up front:** "Use template" (preview) `POST`s an inactive workflow and opens `/workflows/:id`, like Shopify Flow's Install. The button shows a loading state.
     - Server: `POST` and `PUT` follow the same checkpoint 3 rule: 400 with issues, except a save that only turns an active workflow off. Templates ship valid, so "Use template" always succeeds.
     - Remove `NewWorkflowPage`, the `new` route, the first-save redirect and the "record id if edited during first save" handling in `useWorkflowForm`. `router.tsx` goes back to its 002 version: `/workflows/new` never shipped, so it just falls through to `:workflowId`.
   - **"Create workflow" stays as in 002, now inert:** disabled with its own "Coming soon" tooltip in the header and the empty state. Remove `blankGraph()` and the trigger's default block (`newNodeData` is for "+" steps only).
   - **Import button removed** from the list page header (a disabled placeholder from 002).
   - **Template ships valid:** condition `order_total` `gt 500` (store currency) → "Order total is greater than $500".
   - **Delete removes only that step** (button or Backspace, one or several selected): the node and its edges. Descendants stay on the canvas, unlinked. No reconnect, no cascade, no confirmation dialog, no drag-to-connect. Users fix it by deleting unlinked steps or adding a step with the "+" under the trigger (or wherever a handle is free). Remove `deletionKind()` and the confirm dialog; keep `descendantsOf()` for reachability.
   - **Issues (TS `validateWorkflow()` and PHP `WorkflowValidator::errors()`):**
     - Per-node `unreachable` → one workflow-level `unlinked` issue, "Remove or connect unlinked steps", with `nodeIds` (all unlinked steps) and no `nodeId`. No block prefix in the popover; unlinked steps get no Error header from it. Clicking it selects and opens `nodeIds[0]`.
     - `no_action` gets `nodeId` = the trigger, so the trigger shows the Error header and the popover reads "Order status changed: Add an action to this workflow".
     - `invalid_config` unchanged, including on unlinked steps. Every node type shows the Error header for its own issues.
   - Save / Active unchanged from checkpoint 3: disabled while there are issues; deactivate-only saves allowed.
   - Test:
     - Use template → draft appears in the list immediately, no issues (amount 500).
     - Create workflow (header and empty state) → disabled, "Coming soon" tooltip, nothing created.
     - Delete the condition → email action stays, unlinked → "Remove or connect unlinked steps"; Save and Active disabled; clicking the issue opens the email step.
     - Delete the unlinked action → only "Add an action…" remains → add an action under the trigger → Save enabled.
     - Delete a middle action in trigger → A → B → C → C stays unlinked (no reconnect); "+" appears under A.
     - Backspace with several steps selected → only those steps removed, no dialog.
     - Server: `POST` with issues → 400 (inactive or active); valid `POST` → 201; `PUT` with issues → 400; deactivate-only → 200; unlinked graph → a single `unlinked` issue with `nodeIds`.
     - `/wcheck`: unlinked state and Error headers next to Images 5–7, light and dark. `pnpm build`, `php -l`, PHPCS pass.

5. Organize blocks by kind — Risk: 2 — file moves and PHP namespace changes so adding many triggers / conditions / actions later stays easy. No behavior, UI or API change.
   - **Rules:** `git mv` for every moved file so history follows it; no behavior, UI or REST change. Public imports stay the same: consumers keep importing `blocks` and `newNodeData` from `routes/workflows/blocks`.
   - **Frontend** (`apps/admin-page/src/routes/workflows/blocks/`):
     - `core/`
       - `types.ts`: `Block`, `BlockSpec`, `BlockFormProps`.
       - `defineBlock.tsx`: `defineBlock()`, `PLACEHOLDER` (from `block.tsx`).
       - `registry.ts`: `createRegistry(blockList, defaultFor)` → `{ blocks, newNodeData }`. Rejects duplicate ids; `defaultFor` maps condition / action to the block "+" adds.
       - `fields/ConfigField.tsx` and `fields/useValidateOnMount.ts`: shared by all three forms. Anything used by one block stays in that block's folder (e.g. the status multi-select stays with the trigger).
     - One folder per block, named after the block id in kebab-case:
       - `triggers/order-status-changed/`, `conditions/order-total/`, `actions/send-admin-email/`.
       - `schema.ts`: config type, Valibot schema, `defaults()`, `normalize()` (incl. the legacy single-status read).
       - `Form.tsx`: the TanStack form.
       - `index.ts`: `defineBlock({ id, kind, app, label, description, schema, defaults, normalize, summary, Form })`.
     - `blocks/index.ts` only imports the blocks and registers them with `createRegistry`.
     - New `app` field on block definitions, reusing `WorkflowTemplateApp`: `order_status_changed` / `order_total` → `'woocommerce'`, `send_admin_email` → `'email'`. Stored on `Block`, not rendered anywhere.
   - **PHP** (`src/Engine/Blocks/`):
     - `Core/`: `Block`, `Trigger`, `Condition`, `Action`, `BlockRegistry` → namespace `YayAutomation\Engine\Blocks\Core`.
     - `Triggers/OrderStatusChanged`, `Conditions/OrderTotal`, `Actions/SendAdminEmail` → `…\Blocks\Triggers`, `…\Conditions`, `…\Actions`.
     - Update `use` statements in `Runner`, `WorkflowValidator`, `WorkflowRepository`, `AdminPage` and the blocks themselves.
     - Update comments that point at old paths (TS ↔ PHP "mirrors" references).
   - Test:
     - `tsc`, `pnpm build`, ESLint, `php -l`, PHPCS pass.
     - `git log --follow` shows pre-move history for each moved file.
     - REST: `GET /workflows` and the checkpoint 4 server tests unchanged.
     - Browser: Use template → edit the condition (e.g. amount) → Save; summaries, issues and the Sheet forms work as before; no console errors.
     - One real COD order above $500 (e.g. 13 × $39.50) moves to processing on an active template workflow → alert email in the YaySMTP log.
   - Commit: `refactor(workflows): organize blocks by kind`.

## Out of Scope

Other templates/blocks, delays, run log, dedupe, async queue, HTML email, multi-currency conversion, orders created directly in a status (`woocommerce_order_status_changed` needs a `from` status), WooCommerce-inactive notice in the UI. Checkpoint 3: "is" / "is not" operators, per-node descriptions, test-results tab, editing the stored `title`. "Create workflow" (building from scratch): plan 003 only covers Template Library → High-value order alert → Use template; the button stays as in 002, disabled with "Coming soon".

## Meta

- Target branch: jack/templates/high-value-order-alert
- Base branch: main
