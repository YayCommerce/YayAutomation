# Plan: M2 PR A — Wait + run history backend

## Goal
Ship the Wait step and the run history backend (cancel/stop when turned off, runs REST with paging and stats, product grid data) so the 4 cross-sell / upsell templates install. ROADMAP-dev M2.1–M2.5.

## Approach
- **Wait (D1):** `Actions/Wait` in YayMatikModule, category flow, icon clock. `Field::number_unit('delay')`, units minutes/hours/days/weeks, default `{value:1, unit:'days'}`. `validate()`: whole number >= 1, max one year per unit (525600 / 8760 / 365 / 52). Fixed seconds (day 86400, week 7 days). `run()` → `StepResult::wait( now + seconds )`; no outputs. Executor's existing wait/resume path does the rest.
- **TS (D2):** StepForm doesn't draw `number_unit`, so `wait/Form.tsx` stays, moved to the `{ delay: { value, unit } }` shape + weeks + per-unit max matching PHP `validate()`. Shape only; polish is PR B. Templates' `graph()` wait line updated; wait stub out of `pending.ts`; `./run.sh meta-fixture`.
- **Cancel (D4):** `Runtime/Cancellation::waiting( $workflow_id, $reason ): int` is the one place: selects waiting ids, one UPDATE waiting → cancelled (reason in `error`, `wait_until` null, `finished_at` set), their waiting step rows → new `RunStepStore::STATUS_CANCELLED` with the same reason. Callers: REST deactivate, REST apply (under `with_workflow`; response adds `cancelledRuns`), and `RepositoryWorkflows::deactivate` (rate-limit pause). No AS unschedule: leftover jobs are no-ops (claim fails, `keep_waiting` returns). Apply leaves queued/running runs on their pinned version.
- **Stop right away (D5):** Executor checks the workflow is active before each step through `Workflows` (passed by the Worker) with a fresh read: a direct `$wpdb` read of the status meta in `WorkflowRepository`, no object cache. Inactive → run ends cancelled ("The workflow was turned off."). Ending from the Executor or `Worker::start()` closes open step rows (running / waiting) — fixes first-step recovery leaving `running`.
- **REST (D6, D7):** `GET /workflows/{id}/runs?status=a,b&page=n` → `{items, total}`; item adds `subjectLabel` (order number), `subjectUrl` (null if gone), `stepsRun`. `GET /runs/{id}` steps add `stepType` from the pinned graph. Workflow list + GET add `runs30d`, `lastRunAt`, `waitingRuns`; list via one grouped query; all statuses count. `packages/types` updated.
- **Data (D8):** `order.cross_sell_products` / `upsell_products` (block, conditional false), `order.has_cross_sells` / `has_upsells` (boolean), `customer.paid_order_count` (`find_paid_orders()` gets a limit arg; same account + email merge). Products: order lines (variation → parent), merged, unique, minus the order's products, published + visible + in stock, max 4. HTML: email-safe table, 2 per row (image, name, price, link). Text: one line per product "Name — price — URL", price plain (tags stripped, entities decoded). None → ''. Catalog sends `block` for data fields. Preview sample: the store's 4 newest published products.
- **Cleanup (D9):** delete `pending.ts`, `ComingSoonChip`, `isComingSoon`, step/variable `comingSoon`, `comingSoonStep`, `allowPending`, and their test branches.
- D3: `iteration` / `attempt` stay, unwritten. D10: no new Validator issues.

## Checkpoints
1. Wait step + TS shape/templates — Risk: 2 — Wait.php + module + PHPUnit (validate, run); TS schema/form/summary on the new shape, 4 templates, stub removed, meta fixture. Docs: weeks in ROADMAP-dev M2.1, ROADMAP-v1.0 Wait line and open question 4.
2. Cancellation + stop right away + close open rows — Risk: 3 — `Runtime/Cancellation`, `RunStepStore::STATUS_CANCELLED`, callers (REST deactivate/apply with `cancelledRuns`, `RepositoryWorkflows::deactivate`), fresh status read, Executor check, closing open rows in Executor and Worker. PHPUnit: Executor stops when turned off, Worker closes rows, a cancelled run's job does nothing. `/code-review high` before committing.
3. Runs REST + workflow stats — Risk: 2 — filter/paging/total, subject label/url, stepsRun, stepType, runs30d/lastRunAt/waitingRuns grouped query; RunPresenterTest; TS types.
4. Product grid + data fields + preview samples + pending.ts removal — Risk: 2 — fields, grid (HTML + text), catalog block flag, samples; delete coming-soon code; CustomerDataTest, CatalogTest, EmailPreviewTest, parity/variables tests; ROADMAP-v1.0 Editor "States" line.
5. Dev checks + docs + PR text — Risk: 1 — `check-runs`: 1-minute wait resumes via AS; deactivate and apply cancel with count; rate-limit pause cancels; deactivate mid-run stops before the next step. ROADMAP-dev M2 backend ✅, design §1 (Cancellation built) and §3 cancel/stop. PR text in `023-pr.md`.

## Out of Scope
Wait form/card polish, runs table, run detail, dialogs, list columns, Insert variable Blocks group for data fields (PR B). No global runs list, no cancel/retry endpoints, no AS unschedule, no new Validator issues, no `iteration` / `attempt` writes.

## Rules
- .claude/CLAUDE.md: no code for later, one-line docblocks, no compat, docs in the same commit.
- Dead-code search covers `self::m(` from subclasses and `[ X::class, 'm' ]` callables; filter tests by path, never `grep -v test`.

## Meta
- Target branch: jack/feature/m2a-wait-runs
- Base branch: develop (a6a7eee)
