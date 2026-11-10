# Plan: Identity phase 1 — frontend restyle (PR B)

## Goal
Give every workflow screen its own look and wording (plan-identity phase 1, mock v2), reading step icons, summaries and plugin logos from the PHP catalog. No PHP logic change, no behaviour change.

## Approach
- **One step look everywhere.** A shared `StepTile` (icon tile tinted by kind) used on cards, picker rows, panel header, library strips, preview step list, list trigger column and overview summary. Kind tints = three semantic tokens `--yay-kind-trigger / --yay-kind-condition / --yay-kind-action` in this app's `src/styles/theme.css` (light + dark, values from the mock).
- **Card states:** selected = primary ring; "Needs setup" = amber (warning tokens); "Error" = red (destructive).
- **Catalog first.** `steps/core/` gets:
  - `icons.ts`: lucide map for every name in PHP `StepIcons::NAMES`; the step's icon = catalog `icon`, else its TS `icon` (pending steps), else the kind icon.
  - `summary.ts`: renders the catalog `summary` template (`%key%`) from config + `fields`: select/multiselect → option labels ("or"-joined), emails → joined, text/number → value, empty → the field's placeholder or "Not set", `rules` → the condition's rule describer. Keeps the bold/italic marks of `SummaryText`.
  - Built steps drop their TS `summary`; pending steps (Send email, Generate coupon, Wait) keep theirs until PHP ships them.
- **PHP wording pass (wording only).** Step labels, descriptions and summary templates reworded where they lost information or read poorly, e.g. Add order note gets `%type%` (private / customer). Then `./run.sh meta-fixture`; parity tests and PHPUnit must pass.
- **Plugin logo.** `PluginIcon` draws the catalog `logo` SVG (already `wp_kses`-cleaned) instead of the hard-coded lucide icon.
- **YayUiKits only.** Existing primitives: `DropdownMenu`, `Breadcrumb`, `Tabs` (underline via className at use site, like today's `pillTabClass`), `Badge`, `Tooltip`, `Checkbox`, `Empty`, `Card`. No local restyle of `components/ui/`.
- **Wording in TS** per the plan-identity / mock wording table; PHP step wording in CP1 only.
- **Panel boundary:** PR B restyles the picker rows and the step panel *header* (visual only). The panel body — forms, fields, Insert variable — stays as today until PR C.
- Small type fix: `WorkflowIssue.field?` (PHP already sends it).

## Checkpoints
1. ✅ **Foundation: tile, icons, summaries, logos, PHP wording** — Risk: 2 — kind tokens, `StepTile`, `icons.ts`, `summary.ts`, `Step.icon` / `Step.summary` from the catalog, `PluginIcon` with logos, `WorkflowIssue.field?`; PHP step label / description / summary rewording (wording only) + `./run.sh meta-fixture`. Vitest: summary renderer per widget (empty, options, lists, rules); every catalog icon resolves. PHPUnit + parity pass. No visible page change yet except summaries/logos.
   Progress: CP1 done. Summaries shortened to one card line (no step title, ~40 chars, key values): "When a new order is created", "%from% → %to%", "Set to %status%", "Send to %recipients%", "Set %key% to %value%", "%type%: %note%". build, Vitest 69, PHPUnit 325, lint, lint:php pass. `/code-review high`: fixed (placeholder only for multiselect, trigger summary "Order status: %from% → %to%", typed `summaryValues`, one meta source, shared `kindIcons`, bold condition values, test path, design doc example); left for CP2: card `whitespace-pre-line`; noted in `StepSpec.summary` doc: pending steps move unit/plural text to `summaryValues` when PHP ships them.
2. ✅ **Canvas cards** — Risk: 1 — `NodeCardView`: tile + eyebrow (KIND · Plugin; a step named after its kind shows only the plugin) + title + one-line summary; dashed empty card "Choose a trigger / an action / a condition"; primary ring when selected; corner badge "Needs setup" / "Error"; Yes / No pills in the condition card (green check, grey X). `flow.ts`: drop Then / Otherwise line labels. `ReadOnlyCanvas` matches. Lines, arrows, "+" unchanged. Check: editor + template preview, light/dark.
   Progress: CP2 done, checked in the browser (light + dark, editor + template preview). `CARD_WIDTH` 290 in `model/graph.ts`; card min 86px; `MIN_EDGE_GAP` 96 → 84 (no line labels); name and summary cut to one line with a `title` tooltip (`plainSummary()`).
3. ✅ **Picker rows, panel header, editor chrome** — Risk: 1 — visual only. `StepPicker`: tile per row, "All plugins › WooCommerce" breadcrumb; `StepPanel` header: same tile + eyebrow (panel body / forms untouched, PR C); editor header: Activate / Deactivate, Update, "Draft changes"; `IssueBanner`: issue message + "Show step".
   Progress: CP3 done, checked in the browser (light + dark). Shared `StepEyebrow` (card + panel). Header: Activate / Deactivate / Update, "Draft changes" badge, toasts "Workflow activated / deactivated / updated". IssueBanner: neutral bar, step tile, "**Step** needs …" + "Show step", "1 / N" pager. Confirm dialogs (`WorkflowActions`) still old words → CP9. Build + Vitest 70; not checked in a browser.
4. ✅ **Workflow list** — Risk: 2 — trigger column with tile; Runs (30 days) and Last run columns showing "—"; status filter All · Active · Inactive · Draft; "⋯" row menu (Edit, Rename, Activate / Deactivate, Delete in red) replacing the trash icon, reusing the existing dialogs/mutations; "Draft changes" badge; header/empty-state copy ("Browse templates", own empty text); bulk-action wording.
   Progress: CP4 done, checked in the browser (light + dark). `GET /workflows` returns every workflow; the tabs filter the full list, then page it, so counts are right. Draft filter = `workflowState()` (inactive + never activated). Row "⋯" menu → table-level `WorkflowRowDialogs` (one set, survives refresh); row click ignores portaled clicks; Activate hidden while the draft has issues (`draftBlocksActivate`, shared with the Overview). `/code-review high` fixed; open: confirm dialogs still "Turn on / off" (CP9); activation flow in 3 places (editor, Overview, list) → shared hook in CP9; legacy workflows deactivated before the activated_at meta show as Draft (dev data only).
5. ✅ **Create workflow dialog** — Risk: 1 — "What do you want to automate?", describe box with example prompts, disabled with "Coming soon"; "or start on your own"; two start cards Browse templates · Choose a trigger (same actions as today).
   Progress: CP5 done, checked in the browser (light + dark). Fixes after review: opaque background, no focus ring on open, describe box and chips as in the mock, "Coming soon" as the mock's chip. Title "New workflow"; describe box + example prompts shown, not usable ("Coming soon" badge); two start cards; old illustration removed. Build + Vitest 72; not checked in a browser.
6. ✅ **Template Library** — Risk: 2 — sidebar: Categories with counts (All + 4), Plugins with checkboxes (installed first, "Find a plugin", "Show more" past 4); removable filter chips above the grid; cards: step-tile strip, name, 2-line description, plugin icon stack (≤3 + "+N", name on hover). Search and sort kept; blue hero and dashed pills removed. "No templates found" kept.
   Progress: CP6 done, checked in the browser (light + dark, narrow). Plugin filter is OR (`usesAnyOf`, tested). Sidebar (counts follow search + plugins; chosen rows never hidden; Show more / less; above the grid on narrow screens), chips + Clear all, sort above grid; cards: step strip (lines only between linked steps, max 5 + "+N"), category eyebrow, 2-line description, plugin stack (3 + "+N", names on hover), `ComingSoonChip`. Data per card computed once. `/code-review high` fixed, incl. CP4 follow-ups: list Activate hidden when `activateIssues()` (draft, else graph) finds any; row dialogs keep their text while closing. Open: dialog wording (CP9).
7. ✅ **Template preview** — Risk: 1 — breadcrumb back to the library; docked side panel: What it does (one line per step: tile + summary), Plugins used, next step; "Use template" replaces Install; success toast says to review, then activate. "Other versions" and "Before you start" hidden until M1 / M3 data exists.
   Progress: CP7 done, checked in the browser (light + dark, narrow). Use template never checked plugins (server checks shape only); now a plugin not installed shows "Install {plugin} before activating." under Plugins used and in the success toast; using it isn't blocked. Breadcrumb "‹ Template Library › name" (pops history when opened from a card); "Template" badge dropped; docked panel (category, name, description; What it does: tile + name + summary per step in reading order, "Yes:/No:" after a condition; Plugins used with Installed / Not installed; callout); toast "Draft created. Review it, then activate it."; `ReadOnlyCanvas` `controlsPosition` removed (only the old About box used it). Build + Vitest 76.
8. ✅ **Workflow overview** — Risk: 1 — summary row: status ("Active since {date}" from `activatedAt`, Inactive / Draft otherwise), trigger (tile + summary), Runs (30 days) "—", Last run "—"; underline tabs "Workflow" (live) + "Draft changes" (only when present) + "Version history"; the "Workflow runs" section stays where it is today, below the tabs card (not a tab), with its own empty state ("No runs yet" + the ROADMAP-v1.0 line); Activate / Deactivate wording.
   Progress: CP8 done, checked in the browser (light + dark, narrow). Deactivate is a red outline in the editor header too. Summary row (Status "Active since {Oct 2}" / Active / Inactive / Draft; Trigger tile + name + summary; Runs (30 days) and Last run "—"; 2×2 on narrow screens); header badge and Status use `workflowState()` (`onOffState` removed); underline tabs Workflow · Draft changes · Version history; Activate / Deactivate (outline red) + toasts; "Workflow runs" heading + "No runs yet" + docs link kept; "Last updated on …" line dropped (in Version history). Error container wording → CP9.
   Progress: CP9 done, full browser check (all screens, light + dark, narrow) 2026-10-14. `useActivateWorkflow` (requests, toasts, refusals) in editor, Overview, list; `activateIssues()` = saved content + draft, like the server; `canActivate()` for Overview and list; Overview `SetupNotice` (count + each message, Fix in editor); dialogs ActivateDialog / UpdateDialog / DeactivateDialog (name or count); PHP run message "deactivated" + its PHPUnit; TS comments reworded; docs updated (plan-identity status, phase 1, Done-when, product names removed; ROADMAP-dev note; ROADMAP-v1.0 Today lines + ✅). `/code-review high` fixed.
9. ✅ **Dialogs + wording sweep + docs** — Risk: 1 — one shared `useActivateWorkflow` hook for editor, Overview and list, using `activateIssues()` everywhere (so the Overview also hides Activate for a never-activated workflow with issues); `WorkflowActions` ("Activate “{name}”?", Update, toasts "Workflow activated / deactivated"), activation error alert ("{n} steps need setup before this workflow can run."), delete / bulk dialogs. Grep the app for old words (Turn on/off, Apply, Review, True/False, Then/Otherwise, Install, Select a trigger, Empty, Add a variable outside forms) and no product names. Docs: plan-identity status + Done-when ticks that hold; ROADMAP-dev identity note. `/wcheck` all screens light + dark.

Each checkpoint: `pnpm build`, `pnpm test`, `pnpm lint:fix`, then stop for review and commit.

## Out of Scope
- PR C (F1.9–F1.12): `StepForm`, step forms, condition form sentence ("Take the Yes path when…"), "Insert variable" button and picker, read-only "From" notice, TS template parser; Follow-ups (1), (6), (8) from plan 012.
- AI assistant panel / drafting (v2.0) — the describe box only shows "Coming soon".
- Real runs data in list and overview (after M2); run history table.
- Template "Other versions", "Before you start", cart tracking badge, 18 templates (M1, M3, M8).
- PHP logic or REST changes (PHP wording only, CP1).
- Template names / categories rename (separate branch).

## Decisions (2026-10-13)
1. Kind tokens in this app's `theme.css` as `--yay-kind-*`.
2. "Needs setup" = amber; blue = selected; red = error.
3. Picker rows + panel header in PR B (visual only); panel body in PR C.
4. Catalog (PHP) summaries; reword PHP wording where information was lost.
5. Overview tabs: Workflow · Draft changes · Version history; runs empty state in the section below.

## Follow-ups
- Move the `--yay-kind-*` tokens to YayUiKits, then re-copy `theme.css`.
- Exclude `components/ui/` (YayUiKits copies) from `lint:fix` — separate chore (today it rewrites `bubble.tsx`).
- PR C: show merge tags in summaries as variable names, not raw `{{ … }}`.
- After v1: `GET /workflows` returns every workflow (no paging); move paging and filtering to the server when lists get large.
- PR C: "Change action" (swap) button in the panel header, as in mock v2.
- PR C: the forms still use old words and looks: "Add a variable" / "Add variable", IF / AND / "Add criteria", the blue "review" tint on fields (`feedback.ts`, `ConfigField`).
- PR C: REST already returns `issues: { live, draft }` (PHP Validator) on GET / POST / PUT, but the TS `Workflow` type lacks it and the app re-validates in the browser. Add `issues` to the type and use it in the Overview and list, so server-only issues (malformed merge tags) show before a refused Activate.
- PHP comments still say "turned on / off", "Apply", "unapplied" (internal only; reword when those files are next touched).

## Meta
- Target branch: jack/feature/identity-phase-1
- Base branch: develop
- Commits: docs fd9b2d3, CP1 41d3561, CP2 5b3bcce, CP3 713dfd6, CP4 dea5726 (+ fix 50a9d1f), CP5 88c1643, CP6 0623c53, CP7 3d88e42, CP8 f36f236, CP9 (this commit).
- Status: ✅ done 2026-10-14; PR B to open by hand (base develop).
