# Plan: Cleanup sweep

## Goal
Apply `.claude/CLAUDE.md` "Mandatory conventions" and "Scope and dead code" to the existing code: trim comments, delete history and pre-v1.0 compat code, delete dead code. Behavior stays the same, except that the pre-v1.0 compat paths are removed (dev data is reset).

## Ground rules (every checkpoint)
- Don't reformat lines you don't change. phpcbf realigning a docblock you edited is fine.
- Gate per commit, all green: `pnpm build`, `pnpm lint`, `pnpm test`, `pnpm test:php`, `pnpm lint:php`, `./run.sh check-orders`, `./run.sh check-late-module`. CP1 also runs `./run.sh check-storage` (it changes that script) and `./run.sh check-runs` (Runtime, Storage and `VersionStore::insert` change).
- Tests change in the same commit as the code they cover.
- Comment rule, applied to every file a checkpoint touches (PHP, TS, tests):
  - **Keep** only a quirk, a workaround, or a why-not-obvious. Rewrite any comment that describes the past ("older", "saved before", "v0.1", "for now", "later") as a current fact, or delete it.
  - **Delete** decisions, history, plans, comments that restate the code, empty "See X" pointers, and boilerplate ("Hooks Initialization", "Register Facade", "I18n Logic", "Script Name Constants", "YayMatik Plugin Entry Point").
  - **Docblocks:** one line of purpose at most. `@param Type $name` without text (for example, the 14 copies of `$request Request.` in RestAPI). Text stays only where the type can't carry it: array shape, unit, allowed values, null meaning (`null = never`). Keep `@return` / `@throws` only when they add a type or meaning the signature lacks. phpcs needs a class comment, and every `@param` with its type once a function has a docblock.
  - When a class docblock is cut to one line, move each non-obvious rule it held to a `//` beside the code that implements it. A rule that `docs/workflow-core-design.md` already covers can just go.
  - **Interfaces** (`RunRepository`, `Workflows`, `Queue`, `Clock`, `GraphSource`, `CouponWriter`, `Values`): one line per method. The array shape for `CouponWriter::create()` stays here, and only here: drop any copy in `WcCouponWriter`. Implementations add no copy of the interface's docs.
- Measure (baseline below). Comment line = a trimmed non-blank line that starts with `//`, `#`, `/*` or `*`:
  ```bash
  m(){ awk '/^[[:space:]]*$/{next}{t++}/^[[:space:]]*(\/\/|#|\/\*|\*)/{c++}END{printf "%d/%d = %.1f%%\n",c,t,100*c/t}' $(find "$@" \( -name '*.php' -o -name '*.ts' -o -name '*.tsx' \) | grep -v components/ui); }
  m includes/Workflow/Core/Steps   # etc., per area below
  ```
- Dead-code check, PHP: for each `public static function m` in class C, look for `C::m(`, `self::`/`static::m(` (also from subclasses: `Step::scalar` is only called as `self::scalar(` in Criteria and ChangeOrderStatus) and callables `'m' ]` / `'m' )` (`DataRegistry::item_of` is only called as `[ DataRegistry::class, 'item_of' ]`) in `includes/`, `yaymatik.php` and `dev/scripts`. For instance methods, count `->m(` calls against the number of definitions, then check each hit by hand. Hook and REST callbacks (`[ $this, 'x' ]`, `$action . '_workflow'`), the license adapter getters (AdminShell contract), and `SingletonTrait::get_instance` count as used. TS: `pnpm dlx knip` (don't add it to the repo). Run both again before CP7 and after each deletion that may leave more code without a caller (constants, private helpers).

## Baseline (before)
| Area | Comment lines / non-blank lines |
|---|---|
| Core/Steps | 416/1038 = 40% |
| Core/Coupon | 39/108 = 36% |
| Core/Email | 126/368 = 34% |
| Core/Runtime | 417/1251 = 33% |
| Core/Data | 517/1611 = 32% |
| Core/Definition | 274/858 = 31% |
| Core/Storage | 326/1134 = 28% |
| Triggers + Conditions + Actions | 289/1324 = 21% |
| DataItems + Modules + ModuleRegistry + Pro | 121/757 = 15% |
| Engine | 169/823 = 20% |
| root (YayMatik, adapters, Fallback, I18n, Utils) | 44/225 = 19% |
| **includes/ total** | **2738/9497 = 28.8%** |
| tests/ | 536/6337 = 8.5% |
| apps/admin-page/src (no components/ui) | 1370/12525 = 10.9% |
| packages/*/src | 83/281 = 29.5% |

Heaviest files: RunRepository 81%, CouponWriter 79%, Workflows 71%, Trigger 62%, Step 60%, Filters 47%; TS `steps/core/types.ts` 47%.

The final report gives, per area: comment % before/after and lines removed (`git diff --stat develop` plus the measure).

## Decisions
- D1 Keep `YayMatikProLicenseAdapter`; delete only the commented-out code.
- D2 Delete version seeding. Dev sites recreate any workflow made before versions existed.
- D3 Delete the Scope `vars` and `workflow` sections and `set_var()`; keep `run`. The Validator already rejects `{{ vars.x }}` (ValidatorTest:265). Side effect: a trigger key named `vars` or `workflow` would now be reachable as a shortcut. No trigger has such a key.
- D4 Leave `components/ui` and the unused deps alone; list them in the report. This includes `components/motion-ui` and `components/lazy/motion-ui.ts`: `components/ui/color-picker.tsx` imports them, so deleting them would break the typecheck.
- D5 Keep `schemaVersion` and the "newer version" check.
- D6 Delete the test-only `Type` API (below).
- D7 Delete `ClaimStore` and the `trigger_claims` table from `Schema`. No DB version bump and no DROP code: before v1.0, dev data is reset.
- D8 Keep `Field::number_unit()` / `NUMBER_UNIT` / `max()` with the TS number_unit widget, and the runs REST (`GET /workflows/{id}/runs`, `GET /runs/{id}`, `RunPresenter`, TS `Run` / `RunStep` / `RunDetail`). List them in the report as "used by M2".
- D9 GenerateCoupon (report note: after CP3 a config that sends `''` for a number gets the field default (expiry 30), not none; the editor sends null): delete the "missing `code_prefix` → YM" special case. A missing key then reads as `''`, like any text field. Only `GenerateCouponTest:110` relied on it. Templates and the editor send `defaults()`, which include YM, and the TS `normalize` falls back to the defaults for every key.
- D10 Also delete these compat paths: `mark_activated()` in deactivate, `AddOrderNote::sanitize()` (it exists only to keep the v0.1 key order), and `dev/scripts/drop-legacy.sh`.
- D11 Keep the re-sanitize on read in `WorkflowRepository::decode_graph()`. Rewrite its comment as a current fact: stored configs go through the step's `sanitize()` on read. No "older shapes" wording.

## Pre-v1.0 compat inventory (search: older / saved before / legacy / v0.1 / seed / before … existed)
| Where | What | Fate |
|---|---|---|
| WorkflowRepository `has_versions` / `seed_versions` / `ensure_versions` + 4 calls (`update_live`, `save_draft`, `apply_draft`, `record_live`) | version seeding | delete (D2) |
| RepositoryWorkflows::live_version_id seeding | version seeding | delete; return `WorkflowRepository::live_version_id( $id )` |
| Workflows::live_version_id docblock | "seeding first" | one line: live version id, 0 when there is none |
| RestAPI::list_versions seed branch | version seeding | delete; docblock one line |
| VersionStore::insert `$user_id`, `$time` params | only seeding passed them | delete the params; always the current user and now |
| WorkflowRepository::deactivate `mark_activated()` + method + comment | meta backfill | delete (D10) |
| WorkflowRepository::to_array | "turned on before the meta existed" | `everActivated => (bool) $activated_at`, `activatedAt => $active ? (string) $activated_at : null` (it is always set while active). Delete both comments |
| META_ACTIVATED_AT comment | mentions deactivate adding it | one line: last turned on; tells "Inactive" from "Draft" |
| live_version_id docblock | "0 for a workflow saved before versions existed" | "0 when none was saved" (insert failed) |
| decode_graph comment | "older shape" | rewrite (D11) |
| Dispatcher "no saved version" guard | insert can fail | **keep** |
| GenerateCoupon::sanitize `''` → none | "'' from older configs" | delete the `''` branch: only `null` means none. Then `''` → `Field::number` → default |
| GenerateCoupon::sanitize code_prefix line | missing → YM | delete (D9) |
| GenerateCoupon TS `toNumber` string branch | "configs saved before the fields came from PHP" | delete: `typeof raw === 'number' \|\| raw === null ? raw : fallback`. Docblock goes |
| AddOrderNote::sanitize | v0.1 key order | delete (D10). Verify CP3: the StepConfigsTest/TS parity tests don't pin the key order; if one does, update it |
| Type `legacy()` / `from_legacy()` | "older field types" | delete `legacy()`; rename `from_legacy()` → `from_condition_type( string $type )`; class doc: "Conditions compare by the condition field types (`DataItem::TYPE_*`)" |
| Field::html + EmailBody `$message` + EmailBodyTest doc + design §4 line 137 | "saved before the field was rich" | "Plain text is what the editor saves for a message without tags (`"Hi\n\nTom &amp; Jerry"`): it's read as plain text." Keep the code |
| GraphSchema class doc + `equal()` doc | "graphs saved before it existed" | "A missing `schemaVersion` reads as 1: the app never sends it. `prepare()` always puts it first, so a graph with and without it compares equal." Same in `equal()` |
| Catalog::step docblock | "keys the app has read since v0.1" | one line: "A step's catalog entry." |
| dev/scripts/drop-legacy.sh + dev/README.md line 15 | pre-rename data | delete (D10). `run.sh` lists `dev/scripts/*.sh` itself, so there is no entry to remove. Afterwards `grep -rniE "yay.?automation" --exclude-dir={node_modules,vendor,.git,tools} .` returns nothing |
| `useBeforeUnloadWarning` "legacy support, Chrome < 119" | browser quirk | **keep** |
| `useUncontrolled` "prepare for future migration from Antd Form…" | plan | delete the comment |
| VersionHistory "read-only for now" | plan | "Rows are read-only." |
| Filters::add "Yay plugins may add their own later" | plan | goes with D-Filters below |
| RestAPI:393 "later reads don't wait" | seeding | goes with the seed branch |

## Dead code inventory
### PHP: delete
| Symbol | Callers today | Notes |
|---|---|---|
| `RefResolver::prime()` | ContextScope (test support) | goes after the parity test |
| `Scope::set_var()`, `'vars'`, `'workflow'` in `SECTIONS` | ScopeTest | D3; `SECTIONS = [ 'trigger', 'steps', 'run' ]`; class doc line 15 → "`run.*`: plain values" |
| `Type::legacy()` | TypeTest | |
| `Type::number() boolean() datetime() object() parse() name() item()` | tests only | D6. Afterwards remove constants left without a caller (`SCALARS` goes with `parse()`; keep any constant still read). TypeTest checks through `to_string()` and the remaining factories |
| `StepResult::code()` + `$code` | tests | `failed()` keeps the message of a WP_Error |
| `Step::scalar()` | none | protected static, no subclass calls it |
| `StepIcons::exists()` | StepContractTest | tests use `in_array( …, StepIcons::NAMES, true )`. `NAMES` stays: the TS parity test reads it |
| `Filters::input()`, the `$input` arg of `add()`, the per-filter input lists, `TYPE_ANY` | FiltersTest | no validator reads input types; the class docblock claims one does. New doc: "Template filters by name. A filter never fails: input it can't use comes back unchanged." `add( $name, $apply )` |
| `ClaimStore` (whole class), `Schema::TRIGGER_CLAIMS`, its CREATE TABLE and its entry in the install check | `dev/scripts/check-storage.sh` lines 60–66 | D7. Remove the `ClaimStore as C` import and those lines; the header says "runs, versions" |

### PHP: kept, listed in the report
- "Used by M2" (D8): `Field::number_unit()`, `NUMBER_UNIT`, `sanitize_number_unit`, `Field::max()`, the TS number_unit widget; runs REST + `RunPresenter` + TS `Run`/`RunStep`/`RunDetail`.
- Public but used only inside their class (not dead; not changed here): `Field::has_value() options() visible() plain_text()`.

### TS (knip, one run, not added)
Delete files:
- `apps/admin-page/src/routes/workflows/steps/core/fields/schema.ts`
- `apps/admin-page/src/lib/api/api.type.ts`
- `apps/admin-page/src/components/icons/SettingsIcon.tsx`, `TrashIcon.tsx`
- `apps/admin-page/src/routes/workflows/steps/core/panels.ts`: `registerStepPanel` has no caller, so `stepPanel()` is always undefined. Remove `stepPanel` from `defineStep.tsx`: the import, the `panel` branch in `StepConfigForm` (Form → StepForm), and `!stepPanel(spec.id)` in `drawsField`.

Delete exports:
- `PageLink` (components/widget/PageWidget.tsx)
- `parseWpDate`, `getWpNow`, and the `getWpTimezone` re-export (lib/helpers/date.helper.ts)
- `getWpTimezone` in lib/utils.ts (`export { cn } from '@yaymatik/utils'`), then `getWpTimezone` in packages/utils/src/index.ts (no caller left)
- `wordpressApi`; `yayMatikApi`, folded into `export const api = createApi(window.yayMatikMeta.wp.restBase)`
- `type MessageKey` (model/messages.ts)
- packages/types/src/index.ts `WpMeta`, `PluginMeta`, `PaginatedResponse`, `WpRestError` and the header comment ("for YayCommerce plugins… any plugin scaffold"). The file keeps `export * from './workflow'`.

Drop `export` (used only in their file):
- `formatVersionDate` (VersionHistory), `WorkflowNodeCard`, `CurvedEdge`
- `criteriaRequired` (criteria/schema.ts), `fieldError` (catalogSchema.ts), `useFeedback` (feedback.ts)
- `orList` (summary.ts), `FILTERS`, `TemplateFilter` (template.ts)
- `createApi`, `DiscountType`, `ScopeStep`

Keep: `steps/core/pending.ts` stubs (locked M2 templates).

### List only (D4), not changed
- components/ui unused files (27 + tags-input/ ×4) and unused exports (~45), as knip printed them; `components/motion-ui/*` and `components/lazy/motion-ui.ts` (color-picker imports them).
- apps/admin-page/package.json unused deps: @formisch/react, @hookform/resolvers, @phosphor-icons/react, @radix-ui/react-{accordion,avatar,collapsible,context-menu,hover-card,progress,radio-group,scroll-area,slider,toast,toggle}, @tanstack/react-table, date-fns, react-colorful, react-day-picker, react-hook-form, rooks, tailwind-merge (knip misses that packages/utils uses it; check before removing), vaul, zod. devDeps: @typescript-eslint/eslint-plugin, @typescript-eslint/parser.

## Checkpoints
Risk 1 overall (comments, dead code); the compat deletions are risk 2. One commit per checkpoint, message `chore: plan 022 CPn — <area>`, no Co-Authored-By line.

1. ✅ **Core/Runtime + Storage**. Risk: 2.
   - Seeding (whole table above): WorkflowRepository, RepositoryWorkflows, Workflows, VersionStore::insert params, **plus the RestAPI `list_versions` seed branch** (it calls the deleted methods, so it has to change in this commit to stay green). mark_activated, to_array, comments.
   - ClaimStore deletion (D7) + Schema + check-storage.sh. Docs too: `.claude/docs/context-php-backend.md`, `docs/workflow-core-design.md` (§1 table, §2 row), `docs/ROADMAP-dev.md` (F1.1; M8.1 names the claims table as its own task).
   - Comments, per file:
     - RunRepository / Workflows / Queue / Clock / GraphSource: one line per method; `@param` text off except `$from` (statuses it may be taken from), `$at` (null = asap), `$seconds` (window) and `@return` shapes (`all`, `chained`; 0 when not saved).
     - Dispatcher, Executor, Worker class docs → one line each. Keep as `//` at the code: same event twice counts once (collect); parent decided at collect time (exists); loop/rate rules beside the checks (constants); "never throws" on `flush()`/`run()`. For Executor: the queue cursor shape at `position` decode, the resume and interrupted-row rules at the stored-row branch (partly there already), and slice stop at the clock check. For Worker: early-job → requeue at `wait_until` (exists at `ensure_wake`).
     - RunStore: keep the `claim()` MySQL quirk ("an UPDATE that leaves a row as it was counts 0 rows") as `//` in `claim()`; docblocks one line.
     - RunStepStore, VersionStore, Schema, UnsubscribeStore, WorkflowPostType, RunPresenter, SystemClock, ActionSchedulerQueue (keep "pending only" and the two-recovery-jobs reason), Runtime (keep the plugins_loaded reason): rule.
     - `@var` lines on injected collaborators: keep only callable signatures (`fn( int $run_id ): void`).
   - Tests: FakeWorkflows / FakeRunRepository / FakeQueue / FakeClock docblocks; storage tests that referenced claims or seeding.
2. ✅ **Core/Data + Definition**. Risk: 1.
   - Scope (D3), RefResolver::prime, Type (D6 + rename), DataItem call site `Type::from_condition_type()`, Filters input (above).
   - Delete `tests/Support/LegacyVariables.php`, `tests/Support/ContextScope.php`, `tests/Unit/Core/Data/VariablesParityTest.php`. Before deleting, check each rule in its `texts()` and in `test_every_old_tag_renders_the_same` against TemplateTest + `tests/fixtures/template-cases.json`, TemplateHtmlTest and ScopeTest: spacing variants, adjacent tags, unknown path → empty, not-a-tag stays as written, stray and triple braces, HTML around tags escaped / safe display HTML kept, relation alone → empty, display text when the value is empty (`order.created_date`, `order.payment_method`), `paths()` deduped in order. Add a case to TemplateTest (PHP render) only for a rule that loses its only test.
   - ScopeTest: drop `workflow` in `run_scope()`, `set_var`/`vars.tier`/`workflow.name` asserts, the `vars` row in `test_only_trigger_refs_are_read_back`. `vars.none` stays in missing paths.
   - Comments, per file:
     - Template: class doc → one line; keep the `{{`-can't-nest regex comment, `preg_split alternates`, and the `render_html()` escaping list (it is a security contract; trim it to the bullets and drop the prose).
     - HtmlContext: keep all the inline `//` (escaping quirks); class doc → one line.
     - Scope: keep the from_array refs-only-in-trigger reason and "found even when empty"; class doc → one line plus the shortcut rule as a `//` in `resolve()`.
     - DataItem: the field-definition list is an array shape. Keep it compact (key: type, one line each), drop the prose.
     - GraphSchema: rewrite as in the compat table; keep the "one parent / one child also rules out cycles" and "only conditions branch" comments.
     - Validator: class doc → one line; keep "mirrors `validateWorkflow()` in model/validation.ts" (a sync contract) and "ancestors() walks up, so reverse"; `errors()` code list stays only as `@return` shape if needed, else goes (codes live in the TS `IssueCode` type).
     - Catalog, Messages (keep "parity test fails when the TS copy differs"), Operators (keep the PHP 7.4 strpos quirk and the enum/date string-compare reason), Ref, GoneEntity, SampleValues, Values (interface rule), DataRegistry (keep "shape must match `DataItemMeta`").
   - Tests: CatalogTest / GraphShapeTest (lines 11, 181) / ValidatorTest (12, 299) "v0.1" wording → current fact or gone; GraphShapeTest:181 → "schemaVersion always first".
3. ✅ **Core/Steps, Email, Coupon + Triggers/Conditions/Actions/DataItems/Modules**. Risk: 2.
   - StepResult::code, Step::scalar, StepIcons::exists; GenerateCoupon PHP (D9 + `''` branch) and its tests (drop `'text numbers from older configs'` assert line 106 and `test_a_missing_prefix_is_ym…` first assert, keep the empty-prefix part renamed `test_an_empty_prefix_is_none`); AddOrderNote::sanitize (D10) + its "v0.1" doc.
   - Comments, per file:
     - Step: class doc → one line ("One workflow step type; PHP `fields()` is the one source of its settings."). Method docs one line; `outputs()` `@return` keeps its shape; drop `@param` text except `$config` meaning where it differs (`Output of sanitize()` → drop, the type says enough).
     - Trigger: one line each; `listen()` keeps "args must survive JSON (Action Scheduler)"; `run()` phpcs ignore stays.
     - Action, Condition (keep "declared here so the result survives the Executor, which keeps declared outputs only"), StepRegistry, StepResult (class doc's 4 bullets → one line; per-method one-liners stay).
     - Field: factory docblocks → one line or none (`@param string $key`, `@param string $label` without text); html doc rewritten (compat table); `sanitize_all`/`first_error` `@return` shape kept.
     - Email: EmailBody (`$message` rewrite), EmailPreview (class doc → one line; keep the "no real token in previews" rule as `//` where the link is built), UnsubscribeLink (keep why not `wp_salt()` and the race on create as `//`).
     - Coupon: CouponWriter (interface rule; keep the `create()` shape); WcCouponWriter (keep the guests/billing-email quirk); FreeShipping.
     - Actions: each class doc → one line. The `Config: [ … ]` shapes go: `fields()` is the source. Keep the quirks: AddOrderNote wp_kses_post-vs-email, ChangeOrderStatus "no `wc-` prefix, so its own sanitize", GenerateCoupon unambiguous alphabet and the phpcbf `%key%` comment, SendEmail (a variable is checked at send, a lone `<` stays), SendInternalEmail (subject one line).
     - Triggers: OrderCreated (keep the block-checkout draft quirk and META_FIRED reason), OrderStatusChanged, OrderTrigger. Criteria: config shape → one line; keep the "variable with no value never matches" and "without the plugin nothing to check" comments.
     - DataItems: keep the timezone and wpautop quirks in OrderData; CustomerData `is_first_order` doc → one line plus the rules as `//` in the query.
     - Modules, ModuleRegistry (keep "first owner wins" and the `HOOK` timing pointer), Pro modules: rule.
   - Tests: StepConfigsTest:18 "v0.1" → gone; EmailBodyTest:8 rewrite; StepContractTest uses `in_array` for icons.
4. ✅ **Engine + root**. Risk: 1.
   - YayMatik.php: delete the `// TODO: EDD setup` block (lines 23–26) and `// Pro adds YayMatikProLicenseAdapter here.`. Class doc: "Boots the plugin." Keep the HPOS comment.
   - YayMatikProLicenseAdapter: header "Plugin adapter for YayMatik Pro (not registered)." No pointer to `register_admin_menu()`. The trailing `// TODO: EDD setup` on `return 1;` goes; `// --- Menu methods ---` and similar dividers stay only if phpcs or the file needs them (it doesn't, so they go).
   - RestAPI: `@param \WP_REST_Request $request` with no text (×14); route docblocks → one line. Keep the lock rationale on `with_workflow()` as one `//`, "read inside the lock", "a false here is a failed delete", and the preview body shape.
   - Register* / ScriptName / I18n / AdminPage / UnsubscribePage: delete the boilerplate docs (class comment one meaningful line for phpcs). RegisterFacade lines 33/35 → one `//` stating the quirk (the handle may hold `/`; the regex finds this handle's `<script>` tag). UnsubscribePage keeps the scanners/GET-vs-POST rule and the Referer reason.
   - Utils/Logger, SingletonTrait: rule.
5. ✅ **Admin app + packages**. Risk: 1.
   - All TS deletions and dropped exports above. (GenerateCoupon TS `toNumber` was done in CP3.)
   - Comments: `steps/core/types.ts` (47%): one line per member, drop the "Pending steps only:" prose beyond the tag itself, drop the restated defaults. `localize.d.ts`, `packages/types/src/workflow.ts` (keep the `type`-not-`interface` ReactFlow reason; "Block-specific settings" → "Step settings"), `model/messages.ts` (keep the parity line), `validation.ts`, `data.ts` ("Mirrors PHP X" pointers stay: they are sync contracts), `useAutosave.ts`, `flow.ts`, `VariablePicker`, `classicEditor.ts` (keep the TinyMCE quirks).
   - Run `./run.sh meta-fixture` only if PHP meta changed (it shouldn't; the TS parity test confirms).
6. ✅ **Tests-only leftovers**. Risk: 1. Docblock rule across `tests/` not touched above: `tests/Support/Fake*` (FakeOrder 42%, FakeMailer 50%, FakeStep), `stubs.php`, `woocommerce-stubs.php`, `fake-wc.php`, `bootstrap.php`. Test method docblocks that restate the method name go.
7. ✅ **Docs + dev**. Risk: 1.
   - `docs/workflow-core-design.md`: delete §0 (lines 7–35, "Current state (v0.1)" through "Blocks the roadmap"), delete the "Blocks" becomes "Steps" bullet in §1. Links to §0: none today (`grep -n "§0" docs .claude`); re-check and fix any. §4 line 137: rewrite "(saved before the field was rich)" as in the compat table. Status line: unchanged unless it cites §0.
   - `dev/README.md` line 15 + `dev/scripts/drop-legacy.sh` (D10). Confirm the `yay.?automation` grep is empty.
   - No CLAUDE.md growth.
   - Then: final measure, final knip and PHP dead-code pass (anything newly orphaned goes in a fixup to the CP that orphaned it, or is listed), and the report.
8. ✅ **Bare docblocks** (all `includes/` and `tests/`, same rule). Risk: 1.
   - Delete a docblock when it has no purpose text, every param and the return are typed in the signature, and it has no `@throws` or array shape.
   - Keep it when a param is `mixed` or untyped, or it holds a shape (array, callable signature).
   - Gate as above, plus phpcs on every changed file under `tests/` (`pnpm lint:php` covers `includes/` only; the stop hook checks both). Report comment % per area (baseline = after CP7).

## Report (end of CP7)
- Per area: comment % before → after, lines removed.
- D9: after CP3, `''` for a number field (amount, expiry_days, minimum_spend) reads as the field default (expiry 30), not none; the editor sends null for blank. A missing `code_prefix` is `''` in PHP but `YM` in the TS `normalize()` (kept: the editor and templates always send the key).
- Kept by decision: D8 list ("used by M2"), D4 list (components/ui files/exports, motion-ui, deps), D11, `Field` in-class-only publics.
- Unused code noticed outside the task (not deleted): the `run_steps.iteration` column (`Schema`): nothing writes it, so it is always 0; it is only passed on through `RunStepStore::decode()`, `RunPresenter` and the TS `RunStep.iteration`. Same for `run_steps.attempt` (always its default 1, passed on as `RunStep.attempt`). `YayMatikProLicenseAdapter::is_licensed()`: its only caller was the commented-out EDD block (CP4); kept with the adapter (D1).

## Follow-ups
- Version insert failure stores 0: create/activate should fail instead; with seeding gone nothing repairs it → F2 storage rewrite.

## Out of Scope
- components/ui and package.json deps (D4).
- Any behavior change beyond the compat deletions above. No refactors, no renames other than `from_legacy` → `from_condition_type`.
- DB version bump or table DROP for `trigger_claims` (D7).
- ROADMAP and decisions docs: they record history on purpose; they change only if a link breaks.

## Meta
- Target branch: jack/chore/cleanup-sweep (created from develop @ b1bd20a)
- Base branch: develop
