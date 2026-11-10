# Plan: 014 — PR C: F1.9–F1.12 + identity phase 2 + PR B icon fixes

## Goal
Replace per-step forms with a generic catalog-driven `StepForm`, add the TS template parser and "Insert variable" on one available-variables list shared by PHP and TS, restyle forms, condition form and wording per mock v2, and fix icons that don't match the mock.

## Approach
- **One source per fact.**
  - Variables a step can use: public PHP `Validator::variables( graph, nodeId )` and TS `availableVariables(workflow, nodeId)`. Shared JSON cases (graph + node → keys) run in PHPUnit and Vitest.
  - Template errors: TS parser mirrors `Template::errors` / `Validator::tag_error`. Shared cases (text → error, paths).
- **Dotted relations.** `DataRegistry::to_client` adds `relations` per item (key, label, item), entity fields only, not lists. Additive; `meta-fixture` regenerated.
- **Forms.**
  - `StepForm` draws widgets from the step's catalog `fields`.
  - A valibot schema is built from the same fields (required + message, emails, options, merge tags). `defineStep` uses it when a step has no schema.
  - Custom forms (Condition, the 3 pending steps) use `defineStep({ id, Form })`. Plugin forms use internal `registerStepPanel`.
  - A step with no fields (Order created): the panel shows the step description and "No settings needed".
- **From line.** `SendInternalEmail::fields()` builds the notice text as the sender line ("Store <store@…>") from EmailMeta. The `notice` widget draws it as the mock's muted row. No "Change" link.
- **Fixture scrubbing.** `dev/scripts/meta-fixture.sh` adds `pre_option` filters for `woocommerce_email_from_name` ("Example Store") and `woocommerce_email_from_address` ("store@example.test") before `client_meta()`, so the field text and `automation.email` are both scrubbed. Drop the manual `automation.email` fromName / fromAddress overrides. The fixture never holds the dev site's sender.
- **Feedback.** No blue review tint. A step left unfinished shows normal field errors, same as after a failed Activate. A step being filled in for the first time stays quiet.

## Checkpoints
1. **PHP cleanup + icon fixes** — Risk: 2 — ✅
   - **1a. Icons, then stop for a check on `pnpm dev`.**
     - Step icons: Order created and Order status changed → `shopping-bag`; Condition → `git-branch`; Add order note → `message-square`; Change order status → `tag`; Update order meta → `list`. Send internal email (`mail`) unchanged.
     - Pending TS: Send email → `user`; Generate coupon → `ticket`. Wait (`clock`) unchanged.
     - `list` and `ticket` added to `StepIcons::NAMES` and `steps/core/icons.ts`, same order (parity test).
     - WooCommerce logo (`Extend/Plugins.php` `logos()`): lucide `shopping-cart` outline in the primary-tint plugin tile.
     - Sender scrub in `meta-fixture.sh` (see Approach), then `./run.sh meta-fixture`.
     - Only visible change: icons (cards, picker, panel header, library, preview, list).
   - **1b. PHP cleanup.**
     - Public `Validator::variables()` + PHPUnit: trigger data, always data, outputs of the steps above as `steps.<id>.<key>`, dotted relations.
     - `Action::uses()` → `requires()` only (merge tags covered by `tag_error`). Delete `Data/Variables.php`.
     - `Catalog::options()` used by `Field::to_array` and `DataRegistry::to_client`. `DataRegistry` adds `relations`.
     - `SendInternalEmail::fields()` From notice = sender line.
     - Shared fixtures `tests/fixtures/variables.json` and `template-cases.json`, read by PHPUnit (Vitest in CP2).
     - `./run.sh meta-fixture`.
2. **TS data layer** — Risk: 2 — ✅
   - `availableVariables()` in `steps/core`. It replaces `variableScope`'s `available` list and passes the shared cases.
   - TS template parser (F1.11): paths, filters, arguments, old `{{ item.field }}` tags, same messages as `Validator::tag_error`, plus `paths()`.
   - `withKnownVariables` and `uses` use the parser. Drop `VARIABLE_PATTERN` / `variableKeys`.
   - `issues: { live, draft }` on the TS `Workflow` type. Overview and list use it instead of re-validating in the browser.
3. **Generic StepForm (F1.9)** — Risk: 2 — ✅
   - Widgets: text, textarea, emails, select, multiselect, toggle, number, notice. `show_if`; required / placeholder / help.
   - valibot builder from catalog fields. Validation stays in Automation.
   - `defineStep({ id, Form })` + internal `registerStepPanel`.
   - Field look per mock "Step form": no review tint (`feedback.ts`, `ConfigField`). Errors show as normal field errors.
   - No-fields panel: description + "No settings needed".
   - Proof: Add order note and Change order status on StepForm. Delete their `schema.ts` / `Form.tsx`.
4. **Port remaining steps (F1.10)** — Risk: 2 — ✅
   - Order created (no fields), Order status changed, Send internal email (From = notice), Update order meta → StepForm. Delete their `schema.ts` / `Form.tsx`.
   - Pending send_email, generate_coupon, wait keep their custom Form / schema but use the new field components.
5. **Insert variable (F1.12)** — Risk: 2 — ✅
   - "Insert variable" on the label row of `variables` fields. Inserts `{{ key }}` at the cursor.
   - Picker per mock: "Data from the trigger · <tile> Order created"; data tiles Order (shopping-bag), Customer (user), Site (globe) with field counts; a group for outputs of earlier steps (step tiles); dotted paths under their item.
   - Second level: crumb "All data › Order", rows with a key chip, "Coming soon" rows.
   - Inline template errors from the TS parser.
   - Card summaries show merge tags as variable names (unknown tag stays raw).
   - Delete `AddVariable` / old `VariablePicker`.
6. **Condition form + swap + wording** — Risk: 2 — ✅
   - Condition keeps its custom form on the `rules` field type, per mock "Condition form": "Take the Yes path when [all ▾] of these match:", rule boxes (data select with the item in small text, operator, value), "and" / "or" joiner text, "+ Add rule", "Otherwise the No path runs." Branch ids stay `then` / `otherwise`.
   - The rule data select lists data items only (as `Criteria` accepts today), not step outputs.
   - "Change action" (swap icon in the panel header): pick a new action in the picker, keep node id and edges, reset config (confirm if config isn't empty). Expected: later steps that used this step's outputs then show "Needs setup".
   - Remove old words per mock "Wording": "Add a variable", IF, AND/OR select, "Add criteria", True/False.
   - Update ROADMAP-dev F1.9–F1.12 and plan-identity (phase 2, Done when).
   - Full check light + dark (Jack checks on `pnpm dev`).

Every checkpoint: `pnpm build`, `pnpm test`, `pnpm lint`, `pnpm test:php`, `pnpm lint:php`. Mark ✅ + a short Progress line here. Stop after each checkpoint. No commit until Jack says "commit".

## Out of Scope
- Porting send_email / generate_coupon / wait to PHP (M1, M2.1)
- `number_unit` widget (Wait, M2.1)
- "Change" link on the From line
- Template preview / Send test / rendering in TS (PHP renders only)
- Tier 2 templates, plugin-added filters
- Public `registerStepPanel` API (§10)
- Runs UI (M2.3)
- Kind tokens → YayUiKits; `lint:fix` ignoring `components/ui`
- Server-side paging for GET `/workflows`
- M8 / F2 items from plan 012 (`Trigger::context`, `GraphSchema`)
- YayAutomation plugin logo and other Yay plugin logos
- PHP comments with old words unless the file is touched
- Step outputs in condition rules

## Progress
- CP1 ✅ CP1a icons per mock v2 (checked by Jack); CP1b PHP cleanup (`Validator::variables()`, catalog `relations`, `Catalog::options()`, `Variables` dropped, From notice = sender line, shared fixtures). Review high: 6 fixed, 4 skipped (Catalog import cycle = plan decision; relation walker kept apart from `has_field()`; no relations to non-item entities today; TS From form → CP4).
- CP2 ✅ `steps/core/template.ts` parser (PHP trim / stripcslashes) and `variables.ts` `availableVariables()` pass the shared fixtures in Vitest (NBSP case added, PHPUnit agrees). `tagError()` replaces `variableKeys`; relation paths (`order.customer`) accepted like `has_field()`. Validation checks `steps.<id>.<key>` reach like PHP `reach()`; default `uses` = `requires` (tags checked via `tagPaths`), so `uses` doesn't parse. `issues` on `Workflow`; REST list + activate / deactivate / apply / discard now send them; Overview and list read them; the list guards each workflow's check (`Validator::list_issues()`: on a throw, no issues + logged with the id; PHPUnit case). Review high: 7 fixed, 3 skipped (outputs from window meta = same catalog, `variables.ts` can't import the registry; repeat parses in `tagError` are short texts; list cost → Follow-ups).
- CP3 ✅ `core/fields/StepForm.tsx` draws text, textarea, emails, select, multiselect, toggle, number, notice from catalog fields (`showIf`, required / placeholder / help); `catalogSchema.ts` builds the valibot schema (mirrors `Field::validate()`, number_unit and rules included, errors on their field path, tags only after field rules pass). `defineStep({ id, kind })` for built steps; `registerStepPanel` internal. Generic field messages moved to `Messages` (PHP + TS); Change order status checked by its field rules only (`required( 'Choose the new status.' )`; behavior change: an unknown status now says "Choose an option from the list." instead of "Choose the new status.", `StepConfigsTest` updated); dead `validate()` overrides dropped. No review tint: `review` and `error` show the same field errors. "No settings needed." Add order note + Change order status on StepForm, their `schema.ts` / `Form.tsx` deleted. Shared `MultiSelect` (order-status-changed uses it). Review high: 7 fixed, 3 skipped (late panel for a no-fields step; silent skip of number_unit / rules widgets = custom form required; pending schema check stays a load-time throw, caught by any test importing the registry).
- CP4 ✅ Order created, Order status changed, Send internal email, Update order meta on StepForm; their `schema.ts` / `Form.tsx` deleted. PHP `validate()` overrides of the three dropped (field rules decide; behavior change: an unknown status now says "Choose options from the list.", `unknownStatus` removed). Shared `widgets.tsx` (`TextField` with Insert variable on the label row, `ToggleField`, `Notice`); Send email and Generate coupon use them (From = notice, checkboxes → toggles). `automation.email` is now `{ fromLine }` from PHP `from_line()`; `wc.orderStatuses` dropped (unused). Built steps keep only their field keys on normalize; status slugs normalized like `status_slug()`. Send internal email keeps its sample subject / message as TS `defaults` (PHP text fields ignore `default()`); its To placeholder is the admin email (fixture scrubs `admin_email`). Textareas grow with their text (5–16 lines, then scroll). `chooseOptions` reworded to "Choose options from the list.". Review high: 9 fixed, 1 replaced (fixed rows → auto height).
- CP5 ✅ `VariablePicker` rewritten per mock v2: "Insert variable", search, "Data from the trigger" line with the trigger's tile, data rows (tile tinted by source: trigger, neutral for always-available, action for action-added items; description; count; `>`), "From earlier steps" with step tiles and each step's card summary as its line; second level with "All data › X" crumb, relation sections (`order.customer` → "Customer"), key chips, "Coming soon" rows. `groupVariables()` (Vitest). PHP: `DataItem::icon()` added (name from `StepIcons`, PHPUnit), sent as `icon`; coupon stub `ticket`; fixture regenerated. `StepTile` moved to `steps/core/StepTile.tsx` (importers updated, no re-export) with a `neutral` tone. `AddVariable.tsx` → `InsertVariable.tsx`. Inline tag errors (`tagError`) under variable fields once left, even when the form is quiet. Coming-soon fields: listed everywhere, insertable only into a coming-soon step (`canInsert()`, scope `comingSoonStep` from the open step; Vitest). Card summaries name merge tags (`variableNames()`; unknown / malformed / step outputs stay raw). Condition pickers keep data fields only. Review high: 7 fixed, 2 skipped (trigger line over site/coupon per mock; step outputs raw in summaries → Follow-ups); same-type steps fixed after review (summary line).
- CP6 ✅ Condition form per mock: sentence "Take the Yes path when [all / any] of these match:" (one translatable string), rule boxes (data select with the item in small text, comparison, value), "and" / "or" joiner, "+ Add rule", "Otherwise the No path runs."; no description in the condition's panel header. `DataSelect` replaces `VariableLabel` (value side too, × back to a typed value). Comparison labels are sentence phrases ("is greater than") from PHP `Operators::labels()`, sent as catalog `operatorLabels`; TS `operatorLabels` reads them (rules + card summary; TS label map and `phrases` dropped); Criteria's blank hint uses the same label; parity test + PHPUnit check one label per operator. "Change action" (swap icon) in the action panel header → picker with Back; a different step with edited settings (normalized config ≠ defaults) asks first; same node and lines, config reset. Steps the swap breaks get "Needs setup" (`newStepIssues()`, Vitest; `markReview`). Old words gone: Add a variable, IF, AND/OR select, Add criteria, Remove criteria; rule messages say "data" (PHP + TS, fixture regenerated). Docs: ROADMAP-dev F1.9–F1.12 ⏳, plan-identity phase 2 ⏳ + "Phase 2 vs mock v2", wording box ✅, light/dark box open; ROADMAP-v1.0 "Insert variable". Review high: 8 fixed, 1 skipped (DataSelect copies SelectTrigger classes: the kit exports no trigger variants, `components/ui` out of scope). A step that already shows a tag error keeps it after a swap (first issue only), so it isn't re-badged. GET `/workflows/{id}` uses `Validator::list_issues()` (a workflow whose check throws still opens; activate / apply stay strict). Light + dark checked by Jack.

## Follow-ups
- List runs the Validator per workflow on every load; move with server paging after v1.
- Card summaries leave step-output tags raw (`{{ steps.n1.result }}`): `summary(config)` has no graph to name the step. Pass the graph if wanted.
- Send internal email sample subject/message live in TS defaults because PHP text fields ignore ->default(); move to PHP in M1.
- TS `model/messages.ts` still duplicates PHP `Messages` (parity-tested); read them from the catalog like `operatorLabels`.
- "Insert variable" rows: Tab/Enter only, no arrow-key navigation.
- Validator walks relations twice (`variables()` / `entity_variables` vs `has_field` / `reach`); merge with the F2.4 `GraphSchema` helper.

## Meta
- Target branch: jack/feature/step-forms
- Base branch: develop
