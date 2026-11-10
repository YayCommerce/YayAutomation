# Plan: F1 backend (PR A) — data, step contract, executor

## Goal
Workflow Core's new backend (ROADMAP-dev F1.0–F1.8) behind today's UI: every run and step stored, step outputs, typed scope data, templates tier 1, PHP step contract and catalog; the current admin keeps working unchanged.

## Approach
Sources: ROADMAP-dev F1 (+ what M1, M2, M8, v1.1, v2.0 expect from it); design §0–§8; decisions C1–C9, L2, L3, L5, P1, P7; plan-infra Phase 2; plan-identity backend notes; ROADMAP-v1.0 (overview, release criteria, risks). Three checkpoints, one commit each. PR B (identity restyle) and PR C (F1.9–F1.12 forms) follow; F1 ✅ only after PR C.

Rules for the whole PR:
- Keep (design §0): batching at shutdown, `claim()` for once-per-subject triggers, the sanitize / validate split, versions, `uses()` / `provides()` checks, never throw into the host request, Action Scheduler.
- Stored graphs keep `data.block`, branch ids `then` / `otherwise`, issue code `no_block`.
- `yayAutomationMeta.automation` keeps every key the TS reads today; new keys only added. Fixture regenerated after each meta change (`./run.sh meta-fixture`); the 34 TS tests pass.
- Old merge tags `{{ order.total }}` and condition keys `order.total` keep working as shortcuts of `trigger.order.*`.
- Tables `{prefix}yay_automation_<name>`, like `{prefix}yay_automation_versions`.
- PHP 7.4 compatible (phpcs `testVersion 7.4-`): no `enum`, `readonly`, `match`, named arguments, constructor promotion.
- Every new PHP string through `__()`, domain `yay-automation`.
- Privacy (C6, ROADMAP-dev risks): runs store refs, trigger-time facts and declared outputs only; never full objects or rendered email bodies.
- REST controllers call Workflow Core and hold no workflow logic (C1).
- Folders and names (design §1): `Storage/`, `Definition/` (`GraphSchema`, `Validator`, `Catalog`), `Data/`, `Runtime/`, `Steps/`, `Extend/`.
- No other product names in code or docs; no copied icons, SVG or text; plugin logos are our own drawings.

## Checkpoints

1. Data foundation (F1.0–F1.3) — Risk: 2
   Progress: CP1 done (9b10c37)
   - **Docs first:** plan-identity Phases say phase 1 comes after F1 backend (PR B), as in ROADMAP-dev; list columns show "—" until runs exist (ROADMAP-v1.0).
   - **F1.0 PHPUnit** (plan-infra Phase 2): `dev/tools/phpunit/composer.json` (phpunit ^9.6, lock committed), `phpunit.xml.dist`, `tests/bootstrap.php` with its own PSR-4 autoloader for `YayAutomation\` → `includes/` (CI has no main composer install) and small WP stubs. Root `test:php`; `./run.sh setup` installs it; CI step; Stop hook runs it when PHP changes. ✅
   - **F1.1 Storage** (§2, C3, C8) ✅
     - `Storage/Schema`: one installer, `dbDelta` upgrades; keeps option `yay_automation_db_version` and bumps it; absorbs `VersionStore::maybe_install()`.
     - `…_runs` (id, workflow_id, version_id, trigger_id, subject, status, position, scope, wait_until, error, parent_run_id, root_run_id, depth, started_at, finished_at), `…_run_steps` (id, run_id, step_id, iteration, status, input, output, error, attempt, started_at, finished_at), `…_trigger_claims` (workflow_id, subject, claimed_at; unique pair). Indexes on status, workflow, root, wait_until.
     - Run states: queued, running, waiting, completed, failed, cancelled, skipped.
     - `RunStore` (create, find, list, claim with row lock `UPDATE … WHERE status IN (…)`, save position / scope / status / wait_until), `ClaimStore`.
     - Saved versions never change; only the draft row is edited in place. (`VersionStore::update()` also rewrites the live row of a workflow never turned on; such a workflow has no runs, so no pinned version changes.)
     - `VersionStore::find( id )`: load the graph of a pinned version for the executor.
   - **F1.2 Data layer v2** (§4, C6, C7) ✅
     - `Data/Type`: string, number, boolean, date, datetime, money, enum, entity<T>, list<T>, object. `Operators`: boolean `is_true` / `is_false` (no value).
     - Entities: order (`customer`, `items`), order_item, customer, site; typed fields ported from `OrderData`, `CustomerData`, `SiteData`. `DataRegistry::to_client()` keeps its output, adds types.
     - `Ref`, `RefResolver` (load on demand, cache per slice, gone → `GoneEntity`).
     - `Scope`: paths `trigger.*` (shortcuts `order.*`, `customer.*`), `steps.<id>.*`, `vars.*`, `workflow.*`, `run.*`, `site.*`; trigger-time facts (status from/to, event payload) snapshotted; JSON round-trip for `runs.scope`. Built from refs or from given values (sample data for M1.5). Steps read data only through `Scope`, never by caching objects.
   - **F1.3 Templates tier 1** (§6, L3): `Data/Template` parser + renderer `{{ path | filter: arg }}`; filters `default`, `date`, `money`, `upper`, `lower`, `join`, each with an input type; missing → empty, the step never fails for it; HTML mode escapes; no `raw`; one renderer, in PHP; no template library. Replaces `Data/Variables.php`; old tags render. ✅
     - Variables bridge (step 5): `Variables` is a facade over `Template` + `Scope`; old tags byte-identical. ✅
   - Test: PHPUnit for Type, Operators, Scope (paths, shortcuts, round-trip, sample values, gone entity), Template (parse, render, filters, escaping, old tags). `wp eval`: tables created, DB version bumped once, versions table untouched. `pnpm test`, `lint`, `lint:php`, CI pass.

2. Step contract (F1.4, F1.6, F1.7) — Risk: 2
   Progress: CP2 done (27595c7). Real-site checks identical to CP1 (`./run.sh check-orders`, `./run.sh check-status-flow`); test:php 263, Vitest 59, lint, lint:php, build pass. Admin browser checks done in the browser: 5/5 pass.
   - **F1.4 Step contract** (§5, L2, L5)
     - `Step`: `id() label() description() plugin() module() icon()`, `fields()`, `sanitize( raw )` (never fails, every save), `validate( config )` (field rules + extra checks, blocks Activate), `outputs()`, `summary()` (template with `%field%`), `run( Scope, config ): StepResult`, `on_error()` (default `stop`). `Trigger::provides()`, `Action::requires()` / `uses()` kept. ✅
     - `Field` builder: text, textarea, emails, select, multiselect, toggle, number, number_unit, notice, rules (core only); modifiers `required`, `variables`, `placeholder`, `help`, `show_if`. ✅
     - `StepResult::ok( outputs ) | skipped( reason ) | failed( error ) | wait( until )`. ✅
     - Port the 7 steps; same accepted and rejected configs as today. Outputs carry what the M2 run detail shows: Condition `result` (yes/no); Send internal email `to`; Change order status `from` / `to`; Add order note `type`; Update order meta `key`. Condition declares plugin `yayautomation`; Send internal email's "From" is a `notice` field. ✅
     - `icon()` returns a name from core's list, never SVG or a URL. ✅
     - `Extend/Plugins`: `woocommerce`, `yayautomation`, each with a `logo` (own SVG, `currentColor`, no hard-coded colors, `wp_kses`). ✅
     - `Definition/Catalog`: steps (`label`, `description`, `icon`, `fields`, `outputs`, `summary`, `module`, `onError`), plugins, data types for `yayAutomationMeta.automation`; `AdminPage::client_meta()` reads it. `types/localize.d.ts` updated; fixture regenerated. ✅
     - Parity test (Vitest over the fixture): step ids, kinds, plugins, data keys and types, operators used by TS exist in the PHP catalog. ✅
   - **F1.6 Validator v2** (§4, §5, §6, §7, P7)
     - `Definition/Validator` (from `WorkflowValidator`) and `Definition/GraphSchema` (graph shape: ids, kinds, one parent per step, one child per handle, no merge, `schemaVersion`). ✅
     - Available variables per step (trigger data + outputs above + always items); template checks (path, filter); type checks for condition rules. ✅
     - Same codes; issues name their `field`. Messages in our own words with the new wording; TS `validateWorkflow()` gets the same strings; REST delete error reworded. ✅
     - Workflow responses include `issues` for the live graph and the draft (§5: rules only in PHP `validate()` show as the step's issue after a save). The editor shows them in PR C. ✅
     - Graph gets `schemaVersion: 1` on save; missing reads as 1. ✅
   - **F1.7 Modules** (§8, P1): `Extend/Modules` manifest `core` (lite, on) with the 7 steps; `StepRegistry` reads it; off hides its steps; no "is Pro" checks in steps. ✅
   - Test: PHPUnit for Field per widget, each step's accepted/rejected configs, sanitize never fails, outputs, GraphSchema, available variables, `missing_data`, issue `field`, module switch, `schemaVersion`. REST returns `issues` after saving a workflow with a problem. Vitest 34 + parity pass. Admin works as before. ✅

3. Executor and runs REST (F1.5, F1.8) — Risk: 3
   Progress: CP3 done (e83a8d8), review fixes eec48e0. test:php 325, Vitest 59, lint, lint:php, tsc, build pass; `./run.sh check-runs` 30/30; check-orders and check-status-flow identical to CP2/CP3. `/code-review high` on CP3 and on the whole PR: fixed or listed under Follow-ups. Manual checks done 2026-10-13: activatedAt after off/on (console fetch), real order through Action Scheduler → run completed with 3 steps, runs REST list / detail / 404. PR A #13 open (base develop, 4 commits); waiting for CI and review.
   - **F1.5 Executor** (§3, C4, C5, C8, C9)
     - Workflow Core reads a workflow's live version id (today private in `WorkflowRepository::live_version()`), for `runs.version_id`. ✅
     - `Runtime/Dispatcher` replaces `Runner` collect / flush: collect deduplicated events, at shutdown find active workflows, `claim()`, match; per match a `runs` row (queued, `version_id` = live version, trigger, subject, scope, causation) and one Action Scheduler job with the run id. `OrderCreated` keeps its order-meta claim. ✅
     - `Runtime/Worker`: AS hook → row lock → `Executor` slice. State only in `runs`, never read from AS tables. Workflow off before start → `cancelled`; subject gone at start → `skipped` with reason. ✅
     - `Runtime/Executor`:
       - Pinned version's graph (never live). ✅
       - Per step: a `run_steps` row written as `running` before the step runs, then its result (`input` = config with tags unrendered, `output` = declared outputs, status, error, times); the cursor is saved right after each step. ✅
       - Safe to resume (C5): steps already done are never run again; a step found still `running` after an interruption is marked `failed` ("interrupted") and its policy applies, so side effects (emails, coupons) never repeat. ✅
       - Outputs into `steps.<id>`; nothing in PHP variables across steps. ✅
       - Gone entity → step `skipped`; `failed` + `stop` → run `failed`, `continue` → next. ✅
       - `wait( until )` → run `waiting`, `wait_until` saved, AS job scheduled for that time resumes at the cursor (the Wait step itself is M2.1). ✅
       - ~20 s or memory budget → next slice; else `completed`. ✅
     - Never throw into the host request: catch at every entry point; errors to the run or `Logger`. ✅
     - Causation: `parent_run_id`, `root_run_id`, `depth`; events raised during a run carry it as parent; no workflow restarts inside its own chain; max depth 5; over 100 runs a minute → workflow paused (deactivated) and logged. ✅ (agreed change: 100 counts only runs with a parent; ceiling 1000 across all depths)
     - Executor and stores behind small interfaces for PHPUnit fakes. ✅
   - **F1.8 Runs REST**: `GET /workflows/{id}/runs` (list) and `GET /runs/{id}` (detail); workflow responses add `activatedAt`. Status filter, paging and step results are M2.3. ✅
   - Docs: design §2 one line on the table prefix; ROADMAP-dev F2 adds "split graph layout from logic (design §7)"; ROADMAP-dev F1.0–F1.8 marked ⏳. ✅
   - Test:
     - PHPUnit: cursor walk, branches, stop vs continue, skipped, wait and resume, slice resume, interruption → no step runs twice, chain/depth guard, rate limit, no throw from Dispatcher. ✅
     - E2E with wp-cli on a real order: Order created → note; Order status changed → note, meta, staff email, status change; runs `completed` with one `run_steps` row per step and outputs as declared, no email body stored; forced mail failure → `failed`, later steps not run; live graph changed after queueing → old version followed; workflow off before the job → `cancelled`; temporary data removed. ✅
     - REST via `fetch`: list, detail, 404, `activatedAt`. (checked with `rest_do_request`; browser `fetch` snippet given)
     - `/code-review high`; manual review before PR A. (code review ✅; manual review ✅ 2026-10-13)

## Follow-ups (PR A review, whole diff)
Fixed in the review-fix commit: Condition base declares `result`; dead `Variables::render` / `Scope::from_context` / `DataRegistry::display` / `in_context` and `RunStepStore::find_running` removed; docs and comments match the code.
- **PR C** — (1) PHP Validator rejects malformed / unknown tags the TS `variableKeys` (old 2-part regex) still accepts: TS template parser (F1.11) must match `Validator::tag_error`. (8) `[{value,label}]` mapping written three times (`Catalog::options`, `Field::to_array`, `DataRegistry::to_client`): use `Catalog::options()`. (6) `Action::uses()` re-parses tags via `Variables::keys`, ignores `trigger.` paths and non-variable fields: rely on `tag_error`, then drop `Variables`.
- **PR A description note** — (2) Template grammar renders tags the old regex printed verbatim (`{{ order.Number }}`, `{{ order.total.x }}`) as empty; active workflows aren't re-validated.
- **M8 / F2** — (7) `Trigger::context()` only feeds `matches()` while runs use `refs()`; each event loads the order 2–3 times. Make `matches()` read a Scope from `refs()` and drop `context()`.
- **F2.4** — (9) graph helpers duplicated (`Dispatcher::trigger_node`, `Executor::trigger_id` / `children`, Validator loops) and three Template instances: one `GraphSchema` helper, one Template.

## Out of Scope
UI (PR B, PR C) · cancel waiting runs on Deactivate / Update (M2.2) · Wait step (M2.1) · runs filter, paging, step results (M2.3) · scheduled / query triggers with `trigger_claims` (M8.1) · exit conditions (M8.4) · retries, replay, For each, Call workflow, `simulate()` (v1.1) · retention, exporter, eraser, uninstall cleanup (M7.4, release checklist) · CPT → workflows table, `WorkflowStore`, layout split (F2) · registry open to Yay plugins, feature directory (v1.2) · AI provider (v2.0) · entities product, coupon, cart.

## Meta
- Target branch: jack/feature/workflow-core-foundation
- Base branch: develop
