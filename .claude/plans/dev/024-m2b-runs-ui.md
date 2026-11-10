# Plan: M2 PR B — Runs UI

## Goal
Run history UI: list columns, overview summary row, runs table, run detail, waiting-run counts in dialogs, product grid block variables; the 4 cross-sell / upsell templates checked end to end. ROADMAP-dev M2.6–M2.10.

## Decisions (Jack, Oct 23, 2026; checked against code)
- **D1** First commit, alone: ROADMAP-dev ✅ for M1 (phase row + status line + M1.7–M1.10, M1.12 + Templates line) and M2.1–M2.5 (merged #26, 22bf12a); phase table updated (M2 status: UI in progress). Fix `apps/admin-page/src/test/setup.ts:3` comment (pending.ts is gone).
- **D2** Wait (M2.6): no code change. Verify card "Wait 1 day", clock icon, YayMatik picker group (category Flow). Keep the custom form (StepForm can't draw number_unit or per-unit max).
- **D3** Runs table (M2.7) in `pages/overview/WorkflowRuns.tsx`, fed by new `listRuns` / `useRunsQuery`. Columns: Started (short day + time), Subject (`subjectLabel`, link to `subjectUrl` when set), Status badge (Running, "Waiting until <date time>", Completed, Failed, Cancelled; queued shows as Running; `skipped` → "Skipped"), Steps run. Failed / cancelled rows show `error` under the status. Status filter: All · Running (queued,running) · Waiting · Completed · Failed · Cancelled · Skipped (`skipped` is set by `Worker::start()` when the order is gone before the run starts; Jack, CP5). Pages; skeleton rows while loading; keep the current empty state. Remove the "Run logging doesn't exist yet" comment and `runs={[]}` and the `WorkflowRun` interface.
- **D4** PHP `list_runs` response adds `perPage` (`RunStore::PER_PAGE`, stays 20); TS `WorkflowRunsPage` gets it. No page size constant in TS: pages = ceil(total / perPage).
- **D5** Run detail (M2.8): Sheet from the right on the overview, opened by row click and by search param `?run=<id>` (reload / shared link reopens it; closing removes the param). Run of another workflow (`run.workflowId !== workflow.id`), a missing run, or a non-numeric id (rejected client-side as 404, like `getWorkflow`): toast "Run not found." and drop the param. Header: subject (link), status, started / finished. First line = the trigger (step from `run.triggerId`, which is the trigger step type id; time = `startedAt`), then the steps that ran, in order: tile + step label + result + time (`finishedAt`, else `startedAt`). Steps not reached aren't shown; no canvas path view (identity: that's the reference product's pattern). Node gone (`stepType` null): "Removed step".
- **D6** Result text in one TS file mapped by `stepType`: condition `output.result` → Yes / No; wait: waiting → "until <run.waitUntil>", completed → step summary from `input` + "resumed <finishedAt>"; send_email → "Sent to <to>"; send_internal_email → "Sent to <to joined with ', '>" (its `to` is a list); generate_coupon → `code`; change_order_status → "Set to <label of `to`>" (labels from the catalog field options); any other step → its card summary from `input`. Skipped / failed / cancelled step → `error` (e.g. unsubscribed).
- **D7** Links to orders only; customer links come with M4 customer triggers. Update ROADMAP-v1.0 Workflow overview "Run detail" line and ROADMAP-dev M2.8 ("links to the order or customer").
- **D8** List (M2.10): Runs (30 days) = `runs30d`; Last run = `humanTimeDiff(lastRunAt)` (`@wordpress/date`) with full date-time as `title`, plus `lastRunStatus` label under it (Failed in danger text), as mock v2; the status links to `/workflows/<id>?run=<lastRunId>` (mark `data-row-select` / stop propagation so the row click doesn't also fire). Never ran → "—" in both. PHP `RunStore::stats` + `RunPresenter::stats` add `lastRunStatus`, `lastRunId`; `lastRunAt`, `lastRunStatus`, `lastRunId` all come from the newest run by id (same order as the runs list). TS `Workflow` type too. Overview summary row fills the same two cells. Update ROADMAP-v1.0 Workflow list ("Today" line; Last run shows how it ended, links to that run). The list status filter (All · Active · Inactive · Draft) already exists: nothing to build.
- **D9** Dialogs (M2.9) use `workflow.waitingRuns`. Deactivate (editor, overview, row menu, bulk):
  - One: "New events won't start it. Runs under way stop before their next step. You can activate it again at any time."
  - Bulk (several): "New events won't start them. Runs under way stop before their next step. You can activate them again at any time."
  - Then "N waiting runs will be cancelled." (`_n`, left out when 0; bulk = sum of the selected workflows' `waitingRuns`).
  - Update: "It's active, so your draft changes take effect right away. N waiting runs will be cancelled. Runs under way finish on the version they started on." (the waiting sentence left out when 0).
  - Success toasts (deactivate, bulk deactivate, update) add the real count from `cancelledRuns` when > 0 (bulk: sum).
- **D10** After deactivate / apply, invalidate that workflow's runs queries (list + detail). No polling.
- **D11** `Cancellation::turned_off()` text → "The workflow was deactivated." Method name stays. Update asserts in DispatcherWorkerTest (×3), ExecutorTest (×2) and `docs/workflow-core-design.md:55`.
- **D12** Insert variable: data fields with `block: true` become block variables (`variables.ts` `itemVariables` reads `field.block`). Blocks group gets one section per data item labelled with the item label (`itemLabel`, in `VariablePicker.tsx`; step sections keep their card summary). Offered only in html fields, never in Subject / Heading / To or conditions (existing `blocks` / `forConditions` rules).
- **D13** Last checkpoint: manual run check of the 4 cross-sell / upsell templates (wait set to 1 minute, complete an order, run Waiting → Completed, Mailpit email with product grid; deactivate during the wait → Cancelled right away in table and detail; `?run=` link reopens the detail). ROADMAP-dev M2.6–M2.10 ⏳. PR text in `.claude/plans/dev/024-pr.md`.

## Approach
- TS API: `listRuns(id, page, statuses)` → `WorkflowRunsPage`; `getRun(id)` → `RunDetail`. Query keys `workflowKeys.runs(id)` (page/filter under it) and `workflowKeys.run(id)`; `useRunsQuery` (keepPreviousData), `useRunQuery`. Invalidation for deactivate / apply in `useWorkflowActionMutation` path so editor, overview, row menu and bulk all get it.
- Status labels / badge tones and the result-text map live in `pages/overview/` (one file for results, mapped by stepType); list imports the status label through `shared/` if two pages need it (list + overview → put the status label in `shared/ui/`).
- `DeactivateDialog` / `UpdateDialog` take `waitingRuns`.

## Checkpoints
- ✅ **CP0** (`ee37c0b`) — Risk 1 — D1 alone: ROADMAP-dev ✅ marks + phase table; `setup.ts` comment.
- ✅ **CP1** (`d86a5af`) — Risk 2 — PHP `perPage`, `lastRunStatus`, `lastRunId` (newest by id), D11 text + tests + design doc; TS types. (api / query hooks land with their first caller: list keys + `listRuns` in CP3, `getRun` in CP4.) PHPUnit: perPage, `RunPresenter::stats` with last run fields, cancel text. `./run.sh meta-fixture` if PHP meta changed. Report includes wp-cli output of the stats SQL (newest run by id, lastRunStatus, lastRunId, runs30d). `/code-review high` before commit.
- ✅ **CP2** (`f29585a`) — Risk 1 — List columns + overview summary row (D8); ROADMAP-v1.0 Workflow list.
- ✅ **CP3** (`7fe4b21`) — Risk 2 — Runs table (D3). Vitest: status labels. `/code-review high` before commit.
- ✅ **CP4** (`2b57d97`) — Risk 2 — Run detail Sheet + `?run=` (D5–D7); ROADMAP-v1.0 overview + ROADMAP-dev M2.8 text. Vitest: result-text map, `?run=` open / not found. `/code-review high` before commit.
- ✅ **CP5** (`a367158`) — Risk 2 — Dialogs + toasts + invalidation (D9, D10). Vitest: dialog counts. `/code-review high` before commit.
- ✅ **CP6** (`e7833ee`; `?run=` history fix split out as `ec296ba`) — Risk 2 — Insert variable blocks (D12). Vitest: block variables. `/code-review high` before commit.
- ✅ **CP6b** (`454be57`) — Risk 2 — Product grid look (`Email\ProductGrid`), email-client safe (no object-fit, no flex/grid CSS):
  - Each image in a fixed 160×160 cell (centered, light grey background); width/height attributes scaled from the attachment's real size (`wp_get_attachment_image_src`, size `woocommerce_thumbnail`) to fit 160×160; placeholder the same way.
  - Name 14px bold link, at most ~2 lines (truncated in PHP, 60 chars with …); price 14px muted below.
  - An odd last product centered in its row (no empty cell).
  - Table max-width matches the coupon box; margin above and below like the coupon box.
  - Text mode unchanged. Preview and the sent email share `render()`.
  - PHPUnit: scaled sizes (portrait, landscape, square, no image), odd count centered, name truncation. Jack checks Preview + Send test in the browser. `/code-review high` before commit.
- ✅ **CP7** (`8b16389`) — Risk 1 — D2 check, D13 manual run check, `/wcheck`, ROADMAP-dev M2.6–M2.10 ⏳, PR text `024-pr.md`.
  - Scripted check first (wp-cli + REST, no browser), on a test workflow "Order status changed → Completed, Wait 30 minutes, Add order note": complete 2 orders, `wp action-scheduler run --group=yaymatik` → 2 waiting runs; `GET /workflows/{id}` waitingRuns = 2; `POST deactivate` → cancelledRuns = 2, both runs `cancelled` with "The workflow was deactivated."; activate, 2 new waiting runs, save a draft change, `POST apply` → cancelledRuns = 2, reason "The workflow was updated."; deactivate with none waiting → cancelledRuns = 0. Delete the test workflow and restore the orders' statuses after. Report the numbers.
  - Jack's browser check then covers only what a script can't: dialog text, toasts, the runs table switching to Cancelled without a reload.

## Out of Scope
Customer links (M4), polling, canvas path view of a run, global runs list, cancel / retry endpoints, deleting `provides`.

## Rules
- `.claude/CLAUDE.md` conventions: no code for later, one-line docblocks, no compat, docs in the same commit as the code.
- Dead-code search covers `self::m(` from subclasses and `[ X::class, 'm' ]` callables; filter tests by path, never `grep -v test`.
- Unused code to report (don't delete): action `provides` / `providedByAction()` always empty.
- Run status `skipped` is set (`Worker::start()`: the trigger's entity is gone before the run starts): a real label, counts as the last run like any outcome, has its own filter.

## Meta
- Target branch: jack/feature/m2b-runs-ui
- Base branch: develop (22bf12a)

## Progress (for the PR text)
- CP0 ee37c0b · CP1 d86a5af · CP2 f29585a · CP3 7fe4b21 · CP4 2b57d97 · CP5 a367158 · fix ec296ba · CP6 e7833ee · CP6b 454be57 · fix e5416c3 · CP7 8b16389
- CP1 review: one-line docblock, lastRunId doc; found `skipped` IS set (Worker::start); kept D11 wording for the rate-limit pause too (it deactivates); stats query cost unchanged in kind (one more PK join). perPage has no PHPUnit (REST controller is WP-bound): checked with wp-cli (`total=5 perPage=20`); stats SQL matched newest-by-id + runs30d for 3 workflows.
- CP3 review fixes: step back from a page past the last; no previous-filter rows under a new filter; a failed refetch keeps the loaded rows; runs table keyed by workflow. Skipped: Pagination's hard-coded English (kit file), shared paged-table body. Skipped runs also show their reason under the badge (D3 named failed / cancelled only).
- Follow-up → M9.2: kit Pagination (`components/ui/pagination.tsx`) has hard-coded English ("Page X of Y", "Go to").
- CP4 additions: `GET /runs/{id}` now returns `subjectLabel` / `subjectUrl` (the `?run=` header needs them; shared `RunSubject` type). change_order_status needs no own result text: its card summary is already "Set to %status%".
- CP4 review fixes: only a 404 (or another workflow's run) says "Run not found."; other errors stay open with the message; last run kept on screen while the sheet closes; start time is a button (keyboard); `?run=` set merges params; shared `retryUnlessNotFound`; one-line docblocks. Skipped: workflow-scoped run endpoint (D5 kept the client check).
- CP5 review fixes: whole translatable strings (no joined sentences); count held while the dialog runs and closes; runs refresh in `useWorkflowActionMutation` (deactivate / apply). Skipped: dialog counts come from cached stats (toast has the real number); bulk refreshes runs once more; `cancelledRuns` stays in the cached workflow; shared test harness.
- CP6 review fixes: Blocks row sits after the data items, out of "From earlier steps" (the grids come from the trigger); neutral tile, description names the product grid; `blocks` prop docs; picker test (VariablePicker.test.tsx); `?run=` open now replaces history like close (CP4 follow-up: Back no longer needs two presses). Skipped: block fields on related items (none exist).
- Follow-up: Preview picks a recent order that may have no cross-sells / upsells, so the grid is empty with no hint why.
- CP6b review fixes: inline `padding:0` on the square cell, `margin:0` on the image, name in its own `<p>` (WooCommerce inlines `td td` padding, `img` margin-right, wraps bare `<a>` in `<p>`; checked after `style_inline()`); unknown image size → no forced height; entities decoded before cutting a name; one loop for the two cells. Narrow phones fixed in CP6b (Jack): 50% columns, square `width:100%;max-width:160px`, image `max-width:100%;height:auto` with its computed width/height attributes; checked after `style_inline()` (Emogrifier adds `width="100%"` to the square's table: Outlook desktop ignores max-width, so there the square fills the column). Skipped → follow-up: square grey and price color are fixed, not the store's email colors.
- Follow-up: product grid colors (square grey, price) ignore the store's email colors (dark email backgrounds).
- Follow-up (from F1.12, not M2): Insert variable shows the customer twice when the trigger provides both order and customer — the Customer group (`customer.*`) and the Customer section inside Order (`order.customer.*`). Option for later: hide a relation's section when its item is already a top-level group. The Order count (21 = 12 order fields + 9 customer relation fields; the 2 product grids are in Blocks) is correct.
- Follow-up: a workflow runs again when the same order is completed again (duplicate email / coupon); consider a once-per-order guard.
- Follow-up: a run that ends at a Condition's No path shows Completed like one that sent an email; consider a short outcome in the runs table (e.g. "Stopped at Condition (No)").
- `e5416c3` fix: a skipped email's reason is a sentence ("The customer unsubscribed from these emails."), not the code `unsubscribed`.
- CP7 scripted check (workflow 2111, orders 1745 / 1569): waitingRuns 2 → deactivate cancelledRuns 2 ("The workflow was deactivated.") → 2 new waiting → apply cancelledRuns 2 ("The workflow was updated.") → deactivate with none waiting cancelledRuns 0. Cleaned up. Jack's browser check: all passed.
