# Plan: Workflow UI — Shopify Flow

## Goal

Template → Install → edit → Turn on / Apply looks and behaves like Shopify Flow for the existing High-value order alert template, with an active workflow's live version kept running while edits autosave into a separate draft.

## Approach

- **Visual references** (behavior follows this plan; visuals follow the images, built with YayUiKits components and theme tokens, not copied styling):
  - `.claude/references/9-shopify-browse-templates.png` — start of the flow; /wcheck comparison only, the library dialog is unchanged.
  - `.claude/references/10-shopify-template-preview-install.png` — template preview: Install, ×, More templates, About this template.
  - `.claude/references/11-shopify-draft-more-actions.png` — editor header, Draft badge, More actions menu, blue Review header.
  - `.claude/references/12-shopify-draft-autosave-turn-on.png` — autosave status bottom-right, Turn on workflow.
  - `.claude/references/13-shopify-turn-on-error-banner.png` — red Error header, error banner, disabled Turn on.
  - `.claude/references/14-shopify-overview-with-draft.png` — Overview page with Active workflow / Draft tabs.
  - `.claude/references/15-shopify-turn-on-confirm.png` — Turn on confirm dialog.
  - `.claude/references/16-shopify-editor-active.png` — editor for an active workflow with no draft.
  - `.claude/references/17-shopify-edit-active-review.png` — active workflow with a draft: Discard / Apply changes, Review headers.
  - `.claude/references/18-shopify-apply-changes-error.png` — Apply with issues: Error headers, banner with "1 of N", Apply disabled.
- **Routes** (`router.tsx`):
  - `/workflows/:workflowId` → new `WorkflowOverviewPage`.
  - `/workflows/:workflowId/edit` → `WorkflowEditorPage`.
  - Install → `/workflows/:id/edit`.
  - Workflows list: clicking anywhere on a row opens the Overview. The name link stays (keyboard, middle-click); the row's Delete action stops propagation.
- **Data model (server):**
  - `_yay_automation_graph` = the live graph. The only graph `Runner` and `active_for_trigger()` read. `_yay_automation_trigger` changes only with the live graph.
  - New `_yay_automation_draft_graph` = unapplied edits of an active workflow. Exists only while active.
  - New `_yay_automation_draft_updated` = when the draft was last saved.
  - Name isn't versioned; rename applies at once.
  - `post_modified` = "Last updated" on the Overview. Every change to the live graph, status or name bumps it (inactive `PUT` with a graph, activate, deactivate, apply, rename) via `wp_update_post`. Draft-only saves write meta only and don't bump it.
  - Workflows saved before this plan have no draft meta: no migration.
  - `Workflow` type gains `draft: WorkflowGraph | null`, `draftUpdatedAt: string | null`.
- **REST** (all `can_manage`; structural errors — one trigger, known blocks, valid edges — always 400; every endpoint returns the full `Workflow`):
  - `POST /workflows` → create, inactive. Issues allowed. `status` in the body ignored.
  - `PUT /workflows/:id` `{ name?, graph? }`:
    - Inactive: graph replaces the live graph.
    - Active: graph goes into the draft. A draft identical to the live graph clears it.
    - Issues allowed either way. `status` ignored.
  - `POST /workflows/:id/activate` → `errors()` on the graph. Issues → 400 with `data.errors`; else status active.
  - `POST /workflows/:id/deactivate` → always allowed. A draft, if any, becomes the graph and is cleared; status inactive.
  - `POST /workflows/:id/apply` → `errors()` on the draft. Issues → 400 with `data.errors`; else draft becomes live and is cleared. No draft → 409.
  - `DELETE /workflows/:id/draft` → drop the draft. Always 200.
  - `WorkflowValidator::prepare()` keeps structural checks only; the `errors()` gate moves to activate / apply. Remove `only_deactivates()` and TS `onlyDeactivates()`.
- **Workflows list:** an active workflow with a draft shows a small "Unapplied changes" label next to its Active badge.
- **Overview page** (Image 14):
  - Header: `<` (back to the list), app icon, name, status badge (Active / Draft). Right: "Edit" (→ `/edit`), "More actions" (Rename, Delete), "Turn on workflow" / "Turn off workflow" (destructive variant).
  - Under the header: "Last updated on <date>" (`updatedAt`, formatted with `@wordpress/date`).
  - Card with kit `Tabs`:
    - Active: "Active workflow" (`ReadOnlyCanvas` of the live graph) + "Draft" when a draft exists.
    - Inactive: a single "Draft" tab.
  - Canvas: existing zoom controls + a pencil button → `/edit`.
  - Turn on here uses the same confirm dialog; issues → open `/edit` in error mode.
- **Template preview** (Image 10):
  - Header: name + "Template" badge, "Install" (`LoadingButton`), × (back to the library, popping history as today).
  - "More templates" button top-right of the canvas opens the library.
  - "About this template" panel bottom-left, kit `Collapsible`, open by default: description + "Apps used" (icon, label, "Installed" label; WooCommerce reads `wc.active` and shows "Not installed" when off).
  - Remove the right sidebar, Details sheet, Category and Steps sections.
- **Editor header states:**
  - Never active (Images 11–12): name + Draft badge; More actions, Turn on workflow, ×.
  - Active, no draft (Image 16): name, no badge; More actions, Turn off workflow (red), ×.
  - Active with draft (Image 17): name + Draft badge; More actions, Discard changes, Apply changes, ×.
  - × → Overview.
  - Remove the name input, Active switch, Save button, `IssuesPopover`, `WithTooltip`.
- **Autosave** (`useAutosave`):
  - The form edits `draft ?? graph`.
  - Debounced ~800 ms → `PUT { name, graph }`. One request in flight; edits during a save queue one follow-up.
  - Bottom-right pill: "Saving…" / "Saved · Last changed on <date>" (draft time or `updatedAt`) / "Couldn't save" + Retry.
  - Leave guard and beforeunload only while a save is pending or failed. Leaving keeps the autosaved draft.
- **Actions** (each flushes pending autosave first):
  - Turn on (Image 15): "Ready to turn on your automation workflow?" / "Turning on the workflow will activate the automation." / Cancel, Turn on → `activate`. 400 → error mode, Turn on disabled until no issues. Success → Image 16 state + toast.
  - Apply changes: "Apply changes?" / "This workflow is turned on. Once you apply changes, they'll take effect immediately." / Cancel, Apply changes → `apply`. 400 → Image 18 state, Apply disabled until no issues. Success → Image 16 state.
  - Discard changes: `AlertDialog` confirm → `DELETE draft` → reset the form to the live graph.
  - Turn off: `deactivate`. Draft becomes the content; header → never-active state.
  - Rename (More actions): `Dialog` with an `Input`, Save disabled while empty → `PUT { name }`.
  - Delete (More actions): `AlertDialog` confirm → list page (leave guard bypassed) + toast.
  - Rename and Delete behave the same on the Overview.
- **Review / Error / banner:**
  - Before a failed Turn on / Apply: nodes with issues show a blue "Review" header (primary tokens, info icon).
  - Error mode starts when Turn on / Apply returns issues and lasts until the editor unmounts or the action succeeds. Nodes show the red "Error" header.
  - `IssueBanner` (kit `Alert destructive`, top-right of the canvas) in error mode: "Correct the error for this workflow step:" + message + "View '<block label>' step" link that opens the Sheet (`nodeIds[0]` for `unlinked`). No link for workflow-level issues. "1 of N" with prev/next when several.
  - `editor.context`: `errorNodeIds` → `issueNodeIds` + `mode: 'review' | 'error'`.

## Checkpoints

1. Template preview — Risk: 1 — rework the preview (Install, ×, More templates, About panel). Install → `/workflows/:id/edit`; add the `:workflowId/edit` route now pointing at the current editor, `:workflowId` keeps rendering the editor until checkpoint 2. Test:
   - Library → card → preview matches Image 10.
   - × and More templates return to the library; browser Back still matches what's on screen.
   - Install lands on `/edit` with the new draft.
   - Light and dark mode. `pnpm build` passes.
2. Live/draft model + REST, Overview, routes — Risk: 3 — meta, repository, validator split, the six endpoints, `post_modified` rules, `Workflow` type, API client and query hooks, Overview page, `:workflowId` → Overview, list row → Overview, "Unapplied changes" label. The current editor stays usable between checkpoints (Save → `PUT`, switch → activate / deactivate); both are removed in checkpoint 3. Test:
   - Server via console `fetch`:
     - Inactive `PUT` with issues → 200.
     - Active `PUT` → draft set, live graph unchanged. `PUT` equal to live → draft cleared.
     - Activate with issues → 400 with `data.errors`.
     - Apply with issues → 400; valid apply → live updated, draft null; apply with no draft → 409.
     - `DELETE` draft → live unchanged.
     - Deactivate with a draft → draft becomes the graph.
     - `status` in `PUT` ignored. Broken structure → 400.
   - Overview "Last updated" changes after an inactive edit and after Apply, but not after a draft save.
   - A real COD order above $500 uses the live graph while a draft with a different amount exists (YaySMTP log).
   - A workflow saved before this plan (no draft meta, e.g. #1504) loads in the Overview and editor unchanged.
   - Overview tabs: inactive, active, active with a draft. `<` → list. Row click → Overview.
   - List: active workflow with a draft shows "Unapplied changes" next to Active; without a draft, no label.
   - `pnpm build`, `php -l`, PHPCS pass.
3. Editor — Risk: 2 — header states, autosave + status pill, Turn on / Apply / Discard dialogs, Turn off, Review/Error headers, banner, Rename/Delete. Test:
   - Install → edit → Turn on confirm → Active (Image 16).
   - × → Overview → `<` → list.
   - Clear the amount → blue Review, still autosaves → Turn on → red Error + banner + disabled button → fix → Turn on works.
   - Edit an active workflow → Draft badge + Discard / Apply (Image 17).
   - Apply with issues → Image 18 state; View link opens the Sheet; "1 of N" paging.
   - Apply valid → live updated (Image 16). Discard → live graph restored in the editor.
   - Close the tab mid-edit → Overview shows the Draft tab.
   - Turn off with a draft → draft becomes the content.
   - Rename and Delete from the editor and the Overview.
   - Leave guard only while a save is pending or failed.
   - `/wcheck` screenshots next to Images 9–18, light and dark. `pnpm build`, `php -l`, PHPCS pass.

## Out of Scope

"Test your workflow / Select test event", Sidekick, Library dialog unchanged (keeps its existing search and sort); Apps/Categories filters, Add note / Export / Duplicate / Manage tags, "Version history" tab and the "…" menu, versioning beyond one live graph + one draft, new blocks or templates, Create workflow (stays "Coming soon").

## Meta

- Target branch: jack/refactor/workflow-ui-shopify-flow
- Base branch: main
