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
  - New `_yay_automation_draft_graph` = unapplied edits. Checkpoint 2: active workflows only. Checkpoint 5: any workflow that has ever been turned on (`everActivated`), active or inactive; kept across Turn on / Turn off.
  - New `_yay_automation_draft_updated` = when the draft was last saved.
  - Name isn't versioned; rename applies at once.
  - `post_modified` = "Last updated" on the Overview. Every change to the live graph, status or name bumps it (live-graph `PUT`, activate, deactivate, apply, rename) via `wp_update_post`. Draft-only saves write meta only and don't bump it.
  - Workflows saved before this plan have no draft meta: no migration.
  - `Workflow` type gains `draft: WorkflowGraph | null`, `draftUpdatedAt: string | null`, and (checkpoint 4) `everActivated: boolean`.
- **REST** (all `can_manage`; structural errors — one trigger, known blocks, valid edges — always 400; every endpoint returns the full `Workflow`; every write runs under the per-workflow `GET_LOCK`):
  - `POST /workflows` → create, inactive. Issues allowed. `status` in the body ignored.
  - `PUT /workflows/:id` `{ name?, graph? }`, issues allowed, `status` ignored:
    - Never turned on (`everActivated` false): the graph replaces the live graph.
    - Ever turned on (active or inactive; checkpoint 5 — before, active only): the graph goes into the draft. A draft identical to the live graph clears it.
  - `POST /workflows/:id/activate` → 400 with `data.errors` if the live graph has issues, or (checkpoint 5) if a draft exists and has issues. Otherwise status active; the live (saved) graph runs and the draft is kept.
  - `POST /workflows/:id/deactivate` → always allowed; status inactive. Checkpoint 5: the draft is kept (checkpoint 2 merged it into the live graph).
  - `POST /workflows/:id/apply` → `errors()` on the draft. Issues → 400 with `data.errors`; else the draft becomes the live graph and is cleared; status unchanged (checkpoint 5: also for inactive, ever-turned-on workflows). No draft → 409.
  - `DELETE /workflows/:id/draft` → drop the draft. Always 200.
  - `DELETE /workflows/:id` → 409 while active (checkpoint 4).
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

4. Delete guard, Turn off confirm, Inactive state — Risk: 2
   - **Visual references** (behavior follows this plan; visuals follow the images, built with YayUiKits components and theme tokens):
     - `.claude/references/19-shopify-delete-active-blocked.png` — delete blocked on an active workflow.
     - `.claude/references/20-yay-delete-active-current.png` — current (wrong) behaviour: the delete confirm on an active workflow.
     - `.claude/references/21-shopify-turn-off-confirm.png` — Turn off confirm.
     - `.claude/references/22-shopify-overview-inactive.png` — Overview of a workflow that was turned on before and is now off.
   - **Active workflows can't be deleted:**
     - Delete (editor, Overview, list) on an active workflow shows an alert instead of the delete confirm: title "There was a problem deleting this workflow", text "Because this workflow is still turned on, it couldn't be deleted. Turn it off and try again.", one "Back" button.
     - Server: `DELETE /workflows/:id` on an active workflow → 409 with that message, inside the per-workflow lock.
   - **Turn off asks first** (editor and Overview): title "Turn off workflow", text "If you turn this workflow off, it stops running on new orders.", buttons "Dismiss" and "Turn off".
   - **Draft vs Inactive:**
     - `_yay_automation_activated_at` meta, set on the first activate. Workflows active before this checkpoint count as activated; deactivate also sets it when missing.
     - `Workflow` gains `everActivated: boolean` (the UI only needs whether it was ever turned on).
     - Badges (Overview, editor, list): Active / Inactive (turned on before, now off) / Draft (never turned on).
     - Overview tabs: never turned on → "Draft"; Inactive → "Saved workflow"; Active → "Active workflow" plus "Draft" with unapplied changes (unchanged).
     - Editor header for an Inactive workflow: Inactive badge, More actions, Turn on workflow, ×.
   - Test:
     - Delete an active workflow from the editor, Overview and list → alert, nothing deleted. `DELETE` via `fetch` → 409.
     - Turn off → confirm → Dismiss keeps it active; Turn off makes it Inactive.
     - Overview after turning off: Inactive badge and "Saved workflow" tab. A freshly installed workflow still shows Draft. Turn on again → Active.
     - An Inactive workflow can be deleted.
     - Legacy fixture #1546 shows the right badge (Draft: never turned on).
     - `/wcheck` next to Images 19, 21, 22. `pnpm build`, `php -l`, PHPCS pass.

5. Drafts for saved workflows, statuses, confirmations — Risk: 3
   - **Visual references** (behavior follows this plan; visuals follow the images, built with YayUiKits components and theme tokens):
     - `.claude/references/23-shopify-saved-invalid-draft.png` — Saved (inactive) workflow whose draft has issues: no Turn on, only Edit and More actions.
     - `.claude/references/24-shopify-overview-turn-on-errors.png` — Overview error container after a failed Turn on.
     - `.claude/references/25-shopify-edit-with-draft.png` — editor opening an existing draft: Draft badge, Discard / Apply.
     - `.claude/references/26-shopify-overview-active-with-draft.png` — Active workflow with a draft: "Active workflow" + "Draft" tabs.
   - **Status badges:** the workflows list and the Overview header show only "Active" or "Inactive" (a never-turned-on workflow shows "Inactive" there). Only the editor keeps its "Draft" badge. The list's "Unapplied changes" label shows for any workflow with a draft, active or inactive.
   - **Overview error container** (Image 24): a failed Turn on on the Overview no longer opens the editor or shows a toast. Right under "Last updated on …": "Workflow contains errors" + "Before you can turn on this workflow, you need to make some corrections." + "Edit workflow" (opens `/edit` in error mode). Shown only after a failed Turn on; gone when the page is left or Turn on succeeds.
   - **Drafts for every ever-turned-on workflow** (active or inactive):
     - Never turned on: unchanged (edits autosave into the content; Draft badge in the editor; Turn on).
     - Ever turned on: edits autosave into a separate draft; the Active / Saved content stays as is. The draft survives Turn on and Turn off (supersedes checkpoint 2's merge on Turn off).
     - Overview tabs (Image 26): Active → "Active workflow" (+ "Draft" when a draft exists); Inactive and ever turned on → "Saved workflow" (+ "Draft" when a draft exists); never turned on → "Draft".
     - Editor (Image 25): with a draft it opens the draft: Draft badge + "Discard changes", "Apply changes", ×. Also after × or closing the tab mid-edit. Without a draft: Active → no badge + "Turn off workflow"; Saved → Inactive badge + "Turn on workflow".
     - Discard changes → confirm → back to the Active / Saved content; draft deleted.
     - Apply changes on an Active workflow → "Apply changes?" / "This workflow is turned on. Once you apply changes, they'll take effect immediately." with "Cancel" and "Apply". Issues → error mode; none → the draft becomes live and is cleared.
     - Apply changes on a Saved (inactive) workflow → no dialog. Issues → error mode; none → the draft becomes the saved content and is cleared; the workflow stays inactive.
     - Server rules: see REST above (PUT → draft for ever-turned-on workflows; apply for inactive ever-turned-on; deactivate keeps the draft; activate turns on the saved content, keeps the draft).
   - **Saved workflow with an invalid draft** (Image 23): inactive + a draft with issues (`validateWorkflow` on the draft; no Apply attempt needed) → the Overview hides "Turn on workflow", no extra message; only Edit and More actions. Active workflows always keep "Turn off workflow". Server: activate → 400 while the draft has issues. Fixing or discarding the draft brings Turn on back.
   - **Install toast:** "Template installed" after Install creates the workflow (same style as the other toasts).
   - **Delete step confirm:** the step's delete button or Backspace opens "Delete this step?" / "Deleting this step cannot be undone." with "Cancel" and "Delete" (destructive). Several selected steps → one dialog. Supersedes plan 003 checkpoint 4's "no confirmation dialog"; the rules themselves are unchanged (only that step is removed, children stay unlinked; the trigger can't be deleted).
   - Test:
     - List and Overview header show only Active / Inactive; the editor still shows Draft for a never-turned-on workflow. "Unapplied changes" shows for inactive workflows with a draft too.
     - Overview Turn on with issues → error container, no toast, no navigation → "Edit workflow" → editor in error mode.
     - Active + draft → Turn off → Overview "Saved workflow" + "Draft" tabs; Edit opens the draft with Discard / Apply. × mid-edit and a closed tab keep the draft.
     - Discard restores the content. Apply on Active (dialog, Cancel / Apply) and on Saved (no dialog), each with and without issues.
     - Saved + valid draft → Turn on → live is the saved content, the draft is still there.
     - Saved + invalid draft → no Turn on on the Overview; activate via `fetch` → 400; fix or Discard → Turn on returns. Active + invalid draft → Turn off still shown.
     - Install → "Template installed" toast.
     - Delete a step via its button and via Backspace → dialog; Cancel keeps it; Delete removes only that step.
     - A real COD order uses the live content while a draft exists.
     - Never-turned-on workflows otherwise unchanged; legacy fixture #1546 still loads.
     - `/wcheck` next to Images 23–26, light and dark. `pnpm build`, `php -l`, PHPCS pass.

6. Workflows list: tabs, selection, bulk actions — Risk: 2
   - **Visual references** (layout follows the images, built with YayUiKits components and theme tokens):
     - `.claude/references/27-table-with-checkboxes.png` — kit Table with a checkbox column and pagination.
     - `.claude/references/28-bulk-actions-bar.png` — kit bulk actions bar.
   - **Tabs** above the table: "All | Active | Inactive" (kit `Segmented`). Never-turned-on workflows count as Inactive. Switching tabs clears the selection and goes back to page 1.
   - **Table** (kit `Table` in a bordered container, like Image 27): same columns and row content as now (Name, Status badge + "Unapplied changes", Trigger, Last updated, row trash button). New first column: checkboxes. The header checkbox selects / deselects every workflow in the current tab (all pages); indeterminate when some are selected. Row click still opens the Overview; the checkbox doesn't.
   - **Pagination** (kit `Pagination`, bottom right): 10 per page, shown when the tab has more than 10 workflows. Selection is kept across pages.
   - **Bulk actions bar** (kit `BulkActionBox`, bottom left, Image 28): only with 2+ selected. × (clear selection), "N selected", "Delete", and "Turn off" only if at least one selected workflow is active. Replaces the kit's "Bulk Actions" dropdown.
   - **Bulk Turn off:** confirm "Turn off N workflows" / "If you turn these workflows off, the runs in progress will be cancelled." with "Dismiss" and "Turn off". Turns off only the selected active ones (`deactivate` per workflow); drafts are kept.
   - **Bulk Delete:**
     - All selected inactive → "Delete N workflows?" / "These workflows will be permanently deleted." with "Cancel" and "Delete" (destructive).
     - Any selected active → "There was a problem deleting some workflows" / "Because one or more workflows are still turned on, they couldn't be deleted. Turn them off and try again." with only "Back". Nothing is deleted.
     - All or nothing: before any `DELETE`, the selected workflows are re-read (`GET /workflows/:id`); if any is active now (stale list), show the same "problem" dialog, refresh the list, and delete nothing. Deletes then go one per workflow through `DELETE /workflows/:id`, whose 409 stays as the last guard (a workflow turned on between the check and its delete).
   - **After a bulk action:** toast ("N workflows turned off" / "N workflows deleted"), selection cleared, list refreshed.
   - **Single Turn off dialog** (editor and Overview): "Turn off workflow" / "If you turn this workflow off, the runs in progress will be cancelled." with "Dismiss" and "Turn off". No dialog mentions orders (future workflows won't all be order-based).
   - Test:
     - Tabs filter correctly (never-turned-on under Inactive) and clear the selection.
     - 1 selected → no bar; 2+ → bar. Header checkbox selects all in the tab; indeterminate when partial.
     - "Turn off" shown only when an active one is selected. Bulk Turn off confirm → only active ones off, drafts kept.
     - Bulk Delete, all inactive → confirm → deleted. Mixed → problem dialog, nothing deleted. Stale active (turned on elsewhere) → server refuses → problem dialog, list refreshed.
     - Row trash button still works. Pagination with more than 10 workflows.
     - Single Turn off dialog shows the new text in the editor and Overview.
     - Light and dark. `pnpm build`, ESLint pass.

7. Restructure workflows folder — Risk: 2 — file moves only; no UI, API or behaviour change. Goal: many templates and pages later stay easy to maintain.
   - **Target structure** (`apps/admin-page/src/routes/workflows/`):
     - `api/`: `workflow.api.ts`, `workflow.query.ts`, `useCreateDraft.ts` (from `editor/`).
     - `model/`: `graph.ts` (← `editor/workflow.graph.ts`), `validation.ts` (← `validation.ts`). No React.
     - `blocks/`: unchanged.
     - `templates/`:
       - `core/types.ts` (template spec type), `core/defineTemplate.ts`, `core/registry.ts` (`createTemplateRegistry(list)` → `{ templates, findTemplate }`; rejects duplicate ids; in development, asserts every template's graph passes `validateWorkflow()` with zero issues and throws otherwise).
       - `high-value-order-alert/index.ts` (← `templates/templates.ts`, `git mv` so history follows, then trimmed to the one template).
       - `index.ts`: only imports the templates and registers them.
     - `pages/`:
       - `list/`: `WorkflowListPage.tsx`, `WorkflowsTable.tsx`, `WorkflowBulkActions.tsx`, `WorkflowIllustrations.tsx` (only the list page and its library use them; the empty state stays inline in `WorkflowListPage`); `list/library/TemplatesDialog.tsx` (only the list page renders the library; the preview's "More templates" and × navigate to `/workflows?templates=list`).
       - `template-preview/TemplatePreviewPage.tsx`.
       - `overview/WorkflowOverviewPage.tsx`.
       - `editor/`: `WorkflowEditorPage.tsx`, `WorkflowEditor.tsx`, `WorkflowCanvas.tsx`, `WorkflowNodeCard.tsx` (editable card, "+" button, `nodeTypes`), `NodeConfigSheet.tsx`, `IssueBanner.tsx`, `SaveStatus.tsx`, `editor.context.ts`, `useWorkflowForm.ts`, `useAutosave.ts`.
     - `shared/`:
       - `canvas/`: `ReadOnlyCanvas.tsx`, `NodeCardView.tsx` (split out of `WorkflowNodeCard.tsx`: `NodeCardView`, `kindMeta`, `branchLeft`, `readOnlyNodeTypes`, and the `IssueMode` type), `flow.ts` (← `editor/workflow.flow.ts`, incl. `canvasStyle`; it uses React/@xyflow types, so it isn't `model/`).
       - `layout/`: `EditorLayout.tsx` (incl. `WorkflowAppIcon`, `EditorPlaceholder`).
       - `actions/`: `WorkflowActions.tsx` and its dialogs.
       - `ui/`: `WorkflowStatusBadge.tsx`, `ErrorAlert.tsx`, `TemplateApps.tsx` (used by the preview and the library).
       - `nav/`: `library.nav.ts` (`LIBRARY_PATH` = `/workflows?templates=list`), `editor.nav.ts` (`EditorNavState`, the Overview → editor hand-off, moved out of `editor.context.ts`).
     - `router.tsx` imports the pages from `pages/`.
   - **Rules** (also in `.claude/docs/context-dev-convention.md`):
     - Imports go one way: `pages/` → `shared/` → `api/`, `model/`, `blocks/`, `templates/`. Pages never import from another page's folder. `shared/` never imports `pages/`. `blocks/` and `templates/` never import `pages/` or `shared/`.
     - Within the bottom layer: `model/` may import `blocks/`; `templates/` may import `model/` and `blocks/`; `blocks/` imports nothing else from `workflows/`; `api/` imports nothing else from `workflows/`.
     - `model/` has no React (no components, hooks or React types).
     - New template = new folder in `templates/` + one line in `templates/index.ts`.
     - Page-only components live in that page's folder; move to `shared/` only when a second page needs them.
   - **Template check:** dev-time assert in `templates/core/registry.ts` (no new dependencies): in development, a template whose graph has issues throws on load with the template id and the issues.
   - `git mv` for every moved file; split files keep history on the larger part. No UI, API or behaviour change.
   - Test:
     - `tsc`, `pnpm build`, ESLint pass.
     - Import rules: a grep finds no import that breaks them.
     - Browser smoke of every route: list (tabs, selection, bulk Turn off / Delete), library (open via `?templates=list`, close, Back), preview (More templates, ×, Install), Overview, editor (edit + autosave, Turn on, Apply, Discard, delete step).
     - Template check: the current template loads without the assert firing; a temporarily broken copy (e.g. empty amount) throws in development (reverted, not committed).
     - `git log --follow` shows history for moved files.

## Out of Scope

"Test your workflow / Select test event", Sidekick, Library dialog unchanged (keeps its existing search and sort); Apps/Categories filters, Add note / Export / Duplicate / Manage tags, "Version history" tab and the "…" menu, versioning beyond one live graph + one draft, new blocks or templates, Create workflow (stays "Coming soon").

## Meta

- Target branch: jack/refactor/workflow-ui-shopify-flow
- Base branch: main
