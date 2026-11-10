# Plan: 025 — F2 Workflows table (F2.1–F2.4)

## Goal
Workflows live in `workflows` + `workflow_versions` tables behind `WorkflowStore`, written in transactions under one shared lock, with layout stored apart from logic; the CPT and all post meta code are gone (dev data reset, no migration).

## Approach

### D1 Reset, no migration
- Delete `Storage/WorkflowPostType.php` and its boot line in `includes/YayMatik.php:15`. No code reads `yaymatik_wf` or post meta afterwards.
- New `dev/scripts/reset-data.sh` (`./run.sh reset-data`; `/dev` is already in `.distignore`). Line 2 comment = the `./run.sh` listing text.
  - Prompts `Type yes` unless `--yes`.
  - One `wp eval`: deletes `yaymatik_wf` posts and their postmeta by SQL (`DELETE pm FROM postmeta pm JOIN posts p … WHERE p.post_type = 'yaymatik_wf'`, then the posts); `DROP TABLE IF EXISTS` workflows, versions, workflow_versions, runs, run_steps; `delete_option( 'yaymatik_db_version' )`; `as_unschedule_all_actions()` for `yaymatik_run` and `yaymatik_recover_run` (guarded by `function_exists`).
  - Keeps the unsubscribes table and `yaymatik_unsub_secret`. Prints what it removed; a second run finds nothing and still exits 0.
  - The next page load reinstalls the tables (`Schema::maybe_install()`).
- ROADMAP-dev M9 follow-up: delete `reset-data.sh` once every dev site has run it.

### D2–D4 Schema v4
- `Schema::DB_VERSION` 4. Constants: `WORKFLOWS = 'workflows'`, `WORKFLOW_VERSIONS = 'workflow_versions'` (replaces `VERSIONS`). Install check lists all five tables.
- `workflows`: id (AUTO_INCREMENT, the workflow id from now on), name `text` (post titles had no length cap; keeps today's behaviour), status varchar(20), trigger_id varchar(100) DEFAULT '', live_version_id, draft_version_id (bigint, DEFAULT 0), activated_at datetime NULL, created_at, updated_at · `KEY status (status, trigger_id)` — one composite index, which serves `active_for_trigger()` and status lookups (design §2 "index (status, trigger_id)").
- `workflow_versions`: today's `versions` columns + `layout longtext NULL`. `updated_at` stays.
- REST and TS shapes unchanged: `id` is still a string, `draftUpdatedAt` = draft row `updated_at`, `lastVersionAt` = newest version row `updated_at`, `everActivated` = `activated_at` set, `updatedAt` = `workflows.updated_at`.

### D5 WorkflowStore
- `WorkflowRepository` → `WorkflowStore` (file rename). `VersionStore` stays as the row helper for `workflow_versions`; only `WorkflowStore` and `VersionGraphSource` call it.
- Reads: `all()` (one query, `LEFT JOIN` live + draft rows, `MAX(updated_at)` per workflow; sort as today), `find()`, `is_active()` (fresh `SELECT status`, throws on DB error as today), `live_version_id()`, `versions()`, `active_for_trigger()` (`WHERE status = 'active' AND trigger_id = %s`, joins the live row; no draft read).
- Configs still go through each step's `sanitize()` on read (`decode_graph`).
- Callers switched: `RestAPI`, `RepositoryWorkflows`, `Workflows` docblock, `dev/scripts/check-run.php`, `check-runs.php`, `check-orders.php`, `check-storage.sh`. `composer dump-autoload` (classmap-authoritative).

### D6 Transactions
- Private `WorkflowStore::transaction( callable )`: `START TRANSACTION` → callable → `COMMIT`; any `false` from `$wpdb` (insert / update / delete) → `ROLLBACK` + `WP_Error( 'yaymatik_save_failed', …, 500 )`, logged with `last_error`.
- Every multi-row write uses it: `create`, `update_live`, `save_draft`, `activate`, `deactivate`, `apply_draft`, `delete`. A version insert that returns 0 can no longer be stored as `live_version_id`.
- `VersionStore::insert/update/touch/delete*` return the failure instead of only logging, so the transaction sees it.
- `save_draft()` returns `true|WP_Error`; `RestAPI::update_workflow` and `discard_draft` pass it on. `delete()` returns `true|WP_Error` too; the REST 500 message stays.
- Runs of a deleted workflow stay (D10).

### D7 Shared lock
- `RestAPI::with_workflow()`'s `GET_LOCK` moves to `WorkflowStore::locked( int $id, callable $fn )`: same lock name and 10 s wait; not taken → `WP_Error( 'yaymatik_busy', 409 )`; always released in `finally`. `with_workflow()` keeps the read-inside-lock and not-found logic and calls it.
- `RepositoryWorkflows::deactivate()` runs `deactivate` + `Cancellation::waiting()` inside `locked()`, re-reading `is_active()` first (a REST Deactivate may have won). Lock not taken → `Logger::error`; the Dispatcher's `$paused` still blocks runs in this request.

### D8 Layout split
- `workflow_versions.graph` = logic only: `{ schemaVersion, nodes (no position), edges }`; `layout` = `{ nodeId: { x, y } }`.
- `GraphSchema::logic( $graph )` and `GraphSchema::layout( $graph )` from a prepared graph; the store writes both and merges `position` back on read (a node missing from `layout` reads as `{0,0}`), so REST/TS keep `node.position`. `schemaVersion` stays 1.
- `GraphSchema::same()` compares logic only.
- REST `update_workflow` decides by logic, then:
  - Logic same as the row being edited (draft row if any, else live row) → `WorkflowStore::save_layout( $id, $graph )`: `UPDATE workflow_versions SET layout` on that row only. No draft, no new version, no `updated_at` change on the row or the workflow.
  - Draft exists and the new logic equals live → draft cleared (today's rule), and its layout written to the live row.
  - Otherwise as today (draft save, or live edit for a never-activated workflow).
- `record_live()` (activate / deactivate) copies the live row's `layout` into the new version row, so cards keep their place after turning on or off.
- Runtime reads logic only (`VersionGraphSource`); `RunPresenter::detail()` needs node kinds and blocks only, so runs are unaffected.
- **TS change needed** (editor): `hasDraft` in `pages/editor/WorkflowEditor.tsx:79` is true while any autosave is pending, so dragging a card on an activated workflow flashes "Draft changes" until the save returns. Fix: count a pending save as draft changes only when the form's logic differs from `workflow.graph`. New `sameLogic( a, b )` in `model/graph.ts` (nodes' id / kind / data + edges, positions ignored) + Vitest. List badge and overview tab read `workflow.draft` only: no change. Autosave still sends layout-only saves (positions must persist).

### D9 Graph helpers (replace duplicates only)
- `GraphSchema::trigger( array $nodes ): ?array` → `WorkflowStore` write path (trigger_id), `Dispatcher::trigger_node`, `Validator::trigger_step`, `Validator::reachable`, `Executor::trigger_id` (`GraphSchema::trigger( $nodes )['id'] ?? ''`).
- `GraphSchema::parents( array $edges ): array` → `Validator::errors`, `Validator::variables`, `Validator::steps_above`.
- `GraphSchema::by_id( array $nodes ): array` → `Executor::slice`, `EmailPreview::compose`, `Validator::errors`, `Validator::variables`.

### Docs (each with the CP that changes the code)
- CP0: ROADMAP-dev M2 ✅ (phase row, Status line, M2.6–M2.10, Templates line); design §8 hooks; `registerStepPanel` removed (ROADMAP-dev F1.9, V12.2, Public API; design §11 L2 row; decisions L2).
- CP1: decisions C10 Cost ("dev data reset, no migration"; "slashing tricks" without the `write_graph()` reference); ROADMAP-dev line 17, F2.2, F2.3 ("new workflows"); design §2 last bullet; design §11 C10 row; design §1 Storage row (`WorkflowStore`); `.claude/docs/context-php-backend.md` Storage line; design §2 `workflow_versions` row (+ updated_at; D3/D4 part).
- CP2: design §3 Cancel ("under the workflow lock", REST and the rate-limit pause).
- CP3: design §2 `workflow_versions` row (+ layout; "a version's logic never changes; its layout may"); decisions C8 same wording; design §7 says where layout lives; ROADMAP-v1.0 Workflow list "Draft changes": moving a card is not an edit.
- CP4: ROADMAP-dev F2 ⏳ + D10 lines; design §2 bullet "Deleting a workflow deletes its versions; its runs stay until retention cleanup (M7.4)"; ROADMAP-dev M7.4 "cleanup of carts and runs, including runs of deleted workflows".

## Checkpoints
0. ✅ **Docs catch-up** (131ab61) — Risk: 1 — ROADMAP-dev M2 ✅; design §8 lists the PR #28 hooks next to `yaymatik_modules`: `yaymatik_data_item_{id}_fields` (`DataItem::fields`), `yaymatik_trigger_provides` (`Trigger::resolved_provides`), `yaymatik_trigger_refs` (`Trigger::resolved_refs`; a null native ref stays null); `registerStepPanel` removed from ROADMAP-dev F1.9, V12.2 ("plugin steps register through their module; custom forms wait for P4"), Public API, design §11 L2 row and decisions L2 ("plugin steps with custom forms wait for the public API, P4"). Test: docs read right; grep `registerStepPanel` in docs/ → only plan-identity.md.
1. ✅ **Schema v4 + WorkflowStore + transactions + reset** (b2b00d8) — Risk: 3 — D1–D6: Schema v4, `WorkflowStore` on tables, transactions, `reset-data.sh`; REST, runtime and dev scripts switched; CPT removed; CP1 docs. Test: `./run.sh reset-data` (twice), reload → tables exist with DB version 4; `./run.sh check-runs`, `check-orders`, `check-status-flow`, `check-late-module` pass; REST create / edit / activate / draft / apply / deactivate / delete from the console; PHPUnit + phpcs.
2. ✅ **Shared lock** (c9de049) — Risk: 3 — D7 + CP2 docs. Test: `check-runs` rate-limit path (deactivates under the lock, waiting runs cancelled); a held lock (second `wp eval` holding `GET_LOCK`) → REST 409, rate-limit path logs and `$paused` still blocks.
3. ✅ **Layout split + graph helpers** (c2b0b39) — Risk: 2 — D8, D9, editor `hasDraft` + `sameLogic`; CP3 docs. Test: PHPUnit (`GraphShapeTest`: `same()` ignores position, `logic()` / `layout()` round-trip, `trigger` / `parents` / `by_id`); Vitest `sameLogic`; drag a card on an active workflow → no "Draft changes" flash, no new version, position kept on reload; activate / deactivate keep the moved positions on the new version row (`record_live()` copies layout).
4. ✅ **Storage check + wrap-up** (b18cc88) — Risk: 1 — `check-storage.sh` extended, run on a site just reset: lifecycle create → edit → activate → draft → apply → deactivate → delete; version ids never 0; a layout-only save makes no draft and no version and leaves `updated_at`; a failed version insert rolls back (a `query` filter breaks the `workflow_versions` INSERT; no workflow row, no `live_version_id` change, 500). ROADMAP-dev F2 ⏳ + D10 lines (V12.2: soft handling of steps from an inactive plugin; M8 follow-up: trigger `context()` + `refs()` load the order 2–3×); design §2 bullet on deleted workflows' versions and runs; ROADMAP-dev M7.4 adds runs of deleted workflows. Grep docs/ and .claude/docs/ for `WorkflowRepository`, `CPT`, `post meta`, `yaymatik_wf`, `migrat`, `registerStepPanel`; allowed hits only: `docs/plan-identity.md` (history), decisions C10 title + Decision line ("replace the `yaymatik_wf` post type"), the decisions contents row "Custom tables replace the CPT", design §2 opening "Custom tables replace the CPT and post meta", design §11 C10 row; and the lines that state the decision: ROADMAP-dev line 17 and F2.2, design §2 "Why tables" and last bullet, decisions C10 Why, Rejected and Cost. PR text `.claude/plans/dev/025-pr.md`: run `./run.sh reset-data` after pulling, recreate workflows from templates; browser checks (list, editor, drag a card on an active workflow, versions, runs, template install). Report unused code outside the task.

## Settled (2026-10-26)
- All three Validator parents maps and `Executor::trigger_id()` move to the D9 helpers.
- CP4 grep exceptions as listed in CP4: the grep is for docs that describe old code, not lines that state the decision.
- Draft + logic back to live + moved cards → draft cleared, layout written to the live row.
- `name` text, one composite index `(status, trigger_id)`, 10 s lock wait on both paths.

## Out of scope
- Soft handling of steps from an inactive plugin → ROADMAP-dev V12.2 line.
- Trigger `context()` + `refs()` loading the order 2–3× → ROADMAP-dev M8 follow-up.
- Runs of a deleted workflow: stay as today.
- Migration code of any kind; JSON import / export.
- REST / TS shape changes; `schemaVersion` bump.

## Meta
- Target branch: jack/feature/f2-workflows-table (from develop d9ed9c9)
- Base branch: develop
- One PR. Stop after each CP; mark ✅ with its hash here; commit only on "commit" / "go" (staged list first); never push or `gh pr create`.
