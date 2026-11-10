# Plan: 029 — M6 Win-back

## Goal
A customer whose last real order (any status except pending, failed, cancelled or checkout draft) is N days old starts a workflow once for that order, found by a daily job in batches, and the library gains 2 win-back templates.

## Rules for /wdev (from Jack's brief and CLAUDE.local.md)
- One-pass mode: all CPs in one run. After each CP: stop hook green, `/code-review high` on risk 2–3 CPs (fix findings, skip ones about earlier dev data), commit locally by path, mark ✅ + hash here ("✅ **CP2 …** (a1b2c3d)"), go on without waiting.
- Stop and ask only when: tests or lint still fail after two fix attempts; the code contradicts a decision below; an irreversible step is needed (deleting data, `git reset --hard`, a schema change outside this plan); two valid options and the plan doesn't choose. Small choices: take the simplest, log under "Deviations".
- Commits: stage by path (never `git add -A` / `.`); never `.claude/settings*.json`; no Co-Authored-By / Claude-Session lines; message `feat|fix|docs: plan 029 CPn — …`. Review fixes are new `fix:` commits, never amend or rebase. Never `git stash`.
- Never push or `gh pr create`. Plans stay uncommitted.
- Search every symbol added or removed; delete those without a caller (a test alone is not a caller). `composer dump-autoload` after a new PHP class. `./run.sh meta-fixture` after a catalog or `yayMatikMeta` change.
- Docs grep with an allowed-hits list; never reword decision lines to pass it. A difference from the docs updates the docs in the same commit.
- `./run.sh check-winback` grows per CP, so risk 2–3 CPs have a real done-check.
- No other product's name in code or docs. Dates and schedules never in `docs/`.
- UI: build to YayMatik Pages v3 (https://claude.ai/artifact/R6XzCEZqqyVHBe7uaknMoS) #m6, #triggers (`tInactive`), #picker (Customers section), minus the overrides below. YayUiKits only; theme tokens, no `dark:`. Authority: this plan, docs, then the mock.
- End of the PR: one report and stop. Per CP: hash, files, tests, review findings and fixes, deviations. Then: unused code outside the task, browser steps for Jack (no Chrome here), PR text in `.claude/plans/dev/029-pr.md`, with no "Generated with" line.

## What research changed (read this first)
Each item adjusts the brief. Say so in the report.

1. **Anonymized orders would get the email.** `WC_Privacy_Erasers::remove_order_personal_data` sets the billing email to `deleted@site.invalid` (`wp-includes/functions.php:8460`) and meta `_anonymized` = yes (`class-wc-privacy-erasers.php:339`). It runs on an erase request and from WooCommerce's "retain completed orders" setting. A store that anonymizes after 3 months has its anonymized orders in the 90-day window. **Fix:** the sweep skips an order with `_anonymized` set. Not through the `anonymized` query var, which adds a meta join on both stores: the sweep loads each candidate anyway.
2. **Empty guards, as `paid_ids()` has them.** `customer_id => 0` matches every guest, and an empty `billing_email` adds no filter. The account lookup runs only when the id is above 0. A candidate with no billing email is skipped: it has no address and can't be matched to a person.
3. **"No newer order" reads better as "this is the person's latest".** Two lookups, `limit 1`, `orderby 'date ID'`, `order DESC`, no date filter, over the REAL set the candidates come from (item 16); the candidate stays only if each returns it. Same query count as D3. It also settles two orders in the same second: the higher id is the last order, so only one starts, where a `>` date filter would fire both. Accepted by HPOS (`OrdersTableQuery.php:727`, string split by spaces at `:741`) and passed to `WP_Query` on posts. CP5 runs on both stores.
4. **A claim taken before a run that never starts would lose that customer for good.** `start_run()` returns 0 on the rate-limit pause, a missing live version or a gone subject. In M8 the same loss lasts 7 days; here the claim has no end. **Addition to D4:** when `start_run()` returns 0, the Dispatcher deletes the claim it just took, so the next day's sweep retries within the window. A crash between the two writes still loses it (known limit).
5. **Sweep events flush per batch.** `Dispatcher::flush()` runs at `shutdown`, and a long `wp action-scheduler run` process has one shutdown for many batches. Several batches in one process would start runs together, which is what the 200-per-batch rule prevents. **Fix:** after firing a batch, the sweep calls `Runtime::dispatcher()->flush()`. Public already.
6. **Two hooks: `yaymatik_inactive_sweep` (recurring, no args) and `yaymatik_inactive_sweep_batch` (single, `[ days, page ]`).** One hook would work (Action Scheduler's unique check compares args, `ActionScheduler_DBStore.php:232`, and `repeat()` doesn't check at all, `ActionScheduler_ActionFactory.php:228`). Two keep the health check, `ensure()` and `reschedule()` free of any args reasoning, and give each callback one signature.
7. **Paging by `page`, with a re-check.** A batch re-reads the active days first, so turning off the last workflow on that value ends its chain. An order leaving the window during the 2 minutes can shift a page and skip a candidate; the window is 7 days, so the next day's sweep sees it. A duplicate stops at the claim.
8. **Whole number: `Field` has no such rule.** `range_error()` checks min and max only, and `number_or_null()` keeps 90.5. A `validate()` of its own would need a TS form (dev convention). **Fix:** `CustomerInactive::sanitize()` rounds to an int and the TS `normalize` mirrors it, the `OrderStatusChanged` pattern. 90.5 saves as 91.
9. **The claim goes through `RunRepository`.** Dispatcher talks only to interfaces, and `DispatcherWorkerTest` uses `FakeRunRepository`. Two methods: `claim_subject`, `release_subject`. `DbRunRepository` calls the new store. The interface docblock gains `trigger_claims`.
10. **`matches()` reads `$context['days']`.** `CustomerInactive::context()` adds it to the parent's, as `OrderStatusChanged` adds `from` / `to`. `refs()` stays the parent's: days isn't offered as a variable. Preview calls `resolved_refs( [ 'order_id' => … ] )` with no days, which keeps working (D8: no code change).
11. **`Cleanup::run()` returns inside its runs loop.** Claims go between carts and runs, sharing the deadline.
12. **`flow.ts`'s `lineGraph` doesn't fit D6.** It writes `config: {}` on the trigger and makes every step an action, and the coupon must hang from the Condition's `then` handle. Win-back gets a local `graph()`, the `first-order-thank-you` shape, using `customerEmail`, `rule` and `COUPON_LINE`. `couponStep()` is 10% / 7 days; D6 needs `generateCouponStep.defaults()` with overrides. `lineGraph` isn't extended.
13. **Parity forces TS into CP2.** The parity test needs the TS step once the PHP trigger exists, and `HealthCheckId` drives a `Record` in `HealthPanel`. CP2 adds the plain TS step and the health row; CP3 adds the hint and the picker check.
14. **PersonalData: no change.** A claim holds a workflow id and `order:<id>`, no email. Win-back runs are found by the existing `order:` subjects.
15. **The brief's check script moves up.** It starts in CP1, not CP5 (check script grows per CP). Window edges use a margin of a minute: an order exactly N+7 days old is outside by the time the sweep reads it.

Review item (Jack, plan review; the five additions above are approved):
16. **The last real order, one set for both sides.** `REAL` = all `wc_get_order_statuses()` keys except `wc-pending`, `wc-failed`, `wc-cancelled`, `wc-checkout-draft`. Candidates are orders in `REAL` in the window (not `PAID_STATUSES`), and the two "person's latest order" lookups read the same set. So a person's last real order (processing, completed, on-hold, refunded, other plugins' statuses) starts it N days later, and a newer on-hold, refunded or paid order drops an older candidate: a customer waiting on a new order must not get "we miss you" + a coupon. Pending doesn't count: WooCommerce may never cancel one, and with two different sets a newest pending or refunded order would block win-back for good. Trash is never returned. Keys are `wc-` prefixed; both stores take them (`OrdersTableQuery.php:704`, `class-wc-order-data-store-cpt.php:945`). `DraftOrders` adds `wc-checkout-draft` through the `wc_order_statuses` filter, so it must be taken out by name; a status another plugin adds counts as real. D8 stays: Preview reads the newest paid order.

## Decisions against docs and code
Follows / silent / differs. Differences update docs in the CP named.

| # | Result | Note |
|---|---|---|
| D1 Trigger | Follows M6.1, mock #m6. Silent on guests and the person merge. Mock's schedule table says "Insert variable › Customer last order date"; mock #m6 says no new field. Differs: item 16 makes it the last real order, not the last paid order | Override below; CP5 docs |
| D2 Window | Follows mock ("up to 7 days late"). Silent in docs | CP2 design §3 |
| D3 Sweep | Candidates are last real orders, not paid orders (item 16). Follows design §3 "fans out … in batches", ROADMAP-v1.0 risk "Large stores". Differs: C11 "Batching and dedupe come from the Scheduler" (no such class: a job plus the Dispatcher) | Findings 1–3, 5–7; CP2 |
| D4 Claims | Follows M6.1 table. Differs: design §3 "once per customer per 90 days", C11 | Findings 4, 9; CP1 |
| D5 Field | Follows Editor › Trigger panels, mock. Silent on 7–730 | Finding 8; CP3 docs |
| D6 Templates | Follows Template details. `win_back` and `customer_lifecycle` already exist | Finding 12; CP4 |
| D7 Email | Follows Customer emails, mock #m6 | CP4 |
| D8 Preview | Follows: `EmailPreview::latest_paid_order` already does it | No code; check in CP5 |
| D9 Health | Silent; design §9 gains row (e) | CP2 |
| D10 Out of scope | Follows v1.1 Backlog | — |
| Limits | Silent | CP5 decisions |

## Mock overrides
Note for Jack to update the page.
- #triggers `tInactive`: no "days" suffix beside the input. `Field::number` draws none and the label already says days. Same call as "Expires after (days)".
- #m6 / schedule row: remove "Insert variable › Customer last order date". The variable is the existing `order.created_date` ("Order date").
- Description has no final period, like the built triggers.
- #m6 already reads "last order … Pending, failed and cancelled orders don't count as ordering" (item 16): no override.

## Code facts
- WooCommerce 11.2.1, checked in source:
  - `date_created` takes `'<start>...<end>'` Unix timestamps at second precision, inclusive, UTC: HPOS `OrdersTableQuery.php:433`, `:462`, `:504`; posts `parse_date_for_wp_query`, `class-wc-data-store-wp.php:345`. Date strings would be day precision in the site's timezone, so use timestamps.
  - `billing_email` filters on HPOS (`OrdersTableQuery.php:1152`, indexed) and posts (`_billing_email` meta, `class-wc-order-data-store-cpt.php:507`, unindexed value lookup: the "Large stores" cost).
- `Dispatcher::collect` keys on trigger id + JSON args, so one order with two days values is two events.
- `WorkflowStore::active_for_trigger()` returns the live graph; `GraphSchema::trigger( $nodes )` gives the trigger node.
- `HealthRoutes` takes its enum from `HealthCheck::FIXES`, so a new key is enough there.
- `StepIcons` already lists `hourglass`. `TemplateWorkflow`, `workflowRank.win_back` and `customer_lifecycle` exist.
- `FreeShippingWarning` shows from the coupon config on Template preview: no change.
- `reset-data.sh` lists tables and hooks by hand.
- `ModuleRegistryTest::STEPS` lists step ids; `StepContractTest` has an outputs map; `TriggerRefsTest` holds refs tests.

## Design

### trigger_claims (D4)
`Schema`: `DB_VERSION` 7, `TRIGGER_CLAIMS = 'trigger_claims'`, added to the install check list.
```
id bigint unsigned AUTO_INCREMENT, workflow_id bigint unsigned NOT NULL, subject varchar(100) NOT NULL,
claimed_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY workflow_subject (workflow_id,subject)
```
New `Core/Storage/TriggerClaimStore`:
- `claim( int $workflow_id, string $subject ): bool` — `INSERT IGNORE`; true only when exactly 1 row went in. An error returns false: no run, safe side.
- `release( int $workflow_id, string $subject ): void`.
- `delete_orphans( int $limit ): int` — `SELECT c.id … LEFT JOIN workflows … WHERE w.id IS NULL LIMIT`, then `DELETE … IN`. MySQL has no `LIMIT` on a multi-table delete.

`RunRepository`: `claim_subject`, `release_subject`. `DbRunRepository` delegates; `FakeRunRepository` holds an array.

`Trigger::claims_per_workflow(): bool` — false; docblock "Once per workflow per subject, recorded in `trigger_claims`."

`Dispatcher::dispatch`, after `matches()`:
```php
$claims  = $trigger->claims_per_workflow();
$subject = $trigger->subject( $args );
if ( $claims && ! $this->runs->claim_subject( (int) $workflow['id'], $subject ) ) {
    continue;
}
$run_id = $this->start_run( … );
if ( $run_id ) { queue } elseif ( $claims ) { release }
```
`start_run()` stays free of claims: dev scripts call it. `Cleanup::run()`: `delete_orphans( self::BATCH )` loop after carts, within the deadline.

### Customer inactive (D1, D5)
`Triggers/CustomerInactive extends OrderTrigger`: `ID = 'customer_inactive'`, label "Customer inactive", description "A customer has not ordered for a number of days", icon `hourglass`, `category()` `customer`, summary `When %days% days without an order`, `claims_per_workflow()` true. `provides`, `subject`, `refs` inherited.
- `fields()`: `Field::number( 'days', 'Days without an order' )->required()->default( 90 )->min( 7 )->max( 730 )`.
- `sanitize()`: `Field::sanitize_all` then `days` rounded to int when numeric.
- `listen`: `yaymatik_customer_inactive` `( $order_id, $days )` → `$emit( [ 'order_id', 'days' ] )`.
- `context()`: parent + `days`. `matches()`: `(int) $context['days'] === (int) $config['days']`.
Register in `WoocommerceModule`.

### InactiveSweep (D2, D3)
New `Core/Runtime/InactiveSweep`, booted beside `CartSweep` in `YayMatik.php`.
- `HOOK`, `BATCH_HOOK`; `BATCH = 200` (same reason as `CartSweep::BATCH`); `NEXT_AFTER = 2 * MINUTE_IN_SECONDS`; `WINDOW_DAYS = 7`.
- `schedule()` recurring: `time() + HOUR_IN_SECONDS`, daily. `reschedule()`: unschedule `HOOK` with `[]`, schedule.
- `run()`: `foreach active_days() as $days: batch( $days, 1 )`. Nothing active → only the workflows read.
- `batch( int $days, int $page )`: return if `$days` is no longer active. Read `candidates( $days, $page )`. For each that `is_last_order()`: `do_action( 'yaymatik_customer_inactive', $id, $days )`. `Runtime::dispatcher()->flush()`. A full page → `as_schedule_single_action( time() + NEXT_AFTER, BATCH_HOOK, [ $days, $page + 1 ], GROUP )`.
- `candidates( int $days, int $page ): int[]`, the sweep's own query: `wc_get_orders( [ 'type' => 'shop_order', 'status' => self::real_statuses(), 'date_created' => self::window( $days, time() ), 'orderby' => 'date ID', 'order' => 'ASC', 'limit' => BATCH, 'page' => $page, 'return' => 'ids' ] )`. The sweep doesn't use `find_paid_orders()`.
- `NOT_REAL = [ 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-checkout-draft' ]`; `real_statuses(): string[]` = `array_diff( array_keys( wc_get_order_statuses() ), self::NOT_REAL )`.
- `is_last_order( \WC_Order )`: findings 1, 2, 3, item 16. False when `_anonymized` is set or the billing email is empty. Otherwise the candidate's id must equal `latest_order_id( [ 'billing_email' => $email ] )` and, when the customer id is above 0, `latest_order_id( [ 'customer_id' => $id ] )`.
- `latest_order_id( array $person ): int`: `wc_get_orders( $person + [ 'type' => 'shop_order', 'status' => self::real_statuses(), 'limit' => 1, 'orderby' => 'date ID', 'order' => 'DESC', 'return' => 'ids' ] )`.
- Pure, for PHPUnit: `days_of( array $workflows ): int[]` (distinct, from the trigger node), `window( int $days, int $now ): string`.

`Cleanup::ensure()` schedules it when missing; update its docblock.

### Health (D9)
`HealthCheck`: `FIXES['inactive_sweep_job'] = 'schedule'`, `SWEEP_LATE = 86400` (literal, as PHPUnit has no WP constants). Share the `cleanup_job` body through a private helper `( hook, late )`; `fix()` calls `InactiveSweep::reschedule()`.
TS: `HealthCheckId`; `HealthPanel` `ORDER`, `fixRows` (title "The daily inactive-customer check isn’t running on time", help "It’s missing or more than a day late, so customers may start their win-back late or miss it.", fix "Fix: schedule it again.", button "Schedule again"), `fixedMessage` "The daily inactive-customer check is scheduled again."

### Panel (D5)
`steps/triggers/customer-inactive/index.tsx`: `defineStep( { id, kind, normalize, Footer } )`. `Footer` shows a clock icon and "Checked once a day", through `Hint` exported from `triggers/core/CartTriggerNotes.tsx`.

### Templates (D6, D7)
`templates/win-back/index.ts`, `shared = { category: 'customer_lifecycle', workflow: 'win_back', createdAt: '2026-11-03' }`. Local `graph( coupon, offer )`:
`trigger` (`customer_inactive`, `{ days: 90 }`) → `condition` (all: `rule( 'customer.unsubscribed', 'is_false' )`) → `then` → `coupon` (`generateCouponStep.defaults()` + overrides) → `email`. Positions as `first-order-thank-you`.

| Template id | Name | Coupon | Offer line | Description |
|---|---|---|---|---|
| win-back-discount | Win-back + discount | `percent`, 15, `expiry_days` 14 | Here’s {{ steps.coupon.amount }} off your next one: | "When a customer hasn’t ordered for 90 days, send a 15% off coupon, valid for 14 days." |
| win-back-free-shipping | Win-back + free shipping | `free_shipping`, amount null, `expiry_days` 14 | Here’s free shipping on your next order: | "When a customer hasn’t ordered for 90 days, send a free shipping coupon, valid for 14 days." |

`customerEmail( 'We miss you at {{ site.name }}', 'It’s been a while', [ 'It’s been a while since your last order. ' + offer, COUPON_LINE, '{{ site.shop_button }}' ] )`. The helper adds the greeting and the sign-off of D7. The two sentences share one paragraph, per mock.

## Known limits (docs, CP5)
- A last order older than N+7 days at activation never starts it.
- Cron late over 7 days loses those customers.
- An order placed between the sweep and the run still gets the email.
- About 2 lookups per candidate, unindexed on posts stores, repeated daily while an order is in the window; the Dispatcher adds its per-event reads. 200 per 2 minutes is at most 144,000 candidates a day.
- A crash between the claim and the run loses that order's start. A run that couldn't start is retried (finding 4).
- Activating a second win-back version emails every customer still in the window again: claims are per workflow.
- A person is an account plus a billing email: orders under another email as a guest count as someone else.
- An on-hold order that is never paid counts as the last order: the customer gets win-back N days after it.
- A pending order doesn't count as ordering: a customer who left a payment unfinished still gets win-back.
- Claims of live workflows are never deleted: one small row per customer per last order.
- Changing days on a live workflow doesn't restart orders it already claimed.

## Checkpoints
Branch `jack/feature/m6-win-back` from develop 8a94a41.

0. ✅ **CP0 Docs: M3–M5 done** (854b52e) — Risk: 1
   - Files: `docs/ROADMAP-dev.md`: phase table M3, M4, M5 "✅ Done"; each gets "**Status:** ✅ Done. Merged into `develop` (PR #34, `8a94a41`)."; their items ⏳ → ✅.
   - Done: `grep -n "⏳" docs/ROADMAP-dev.md` shows no M3–M5 line.

1. ✅ **CP1 trigger_claims + store + Dispatcher claim** (57bf1e4, fix 03d60ef) — Risk: 3
   - Files: `Core/Storage/Schema.php`; new `Core/Storage/TriggerClaimStore.php`; `Core/Steps/Trigger.php`; `Core/Runtime/RunRepository.php`, `DbRunRepository.php`, `Dispatcher.php`, `Cleanup.php`; `tests/Support/FakeRunRepository.php`; `tests/Unit/Core/Runtime/DispatcherWorkerTest.php` (opt-in: same subject twice → one run; two workflows → one each; opt-out unchanged; no live version → claim released, retried later); `dev/scripts/reset-data.sh` (table); new `dev/scripts/check-winback.{sh,php}`.
   - Docs: design §2 (table row), §3 "Scheduled and query triggers" (once per workflow per subject; opt-in; release) and "Cleanup"; §5 step contract if it lists `Trigger` methods; decisions C11 Decision and For devs.
   - check-winback adds: table present at DB version 7; claim twice → true, false; release → true again; same subject on another workflow → true; orphans of a deleted workflow go through `Cleanup::run()`, a live workflow's stay.
   - Done: `check-winback`, `check-runs`, `check-storage` PASS; PHPUnit.

2. ✅ **CP2 Customer inactive trigger + sweep + schedule + health row** (0a1fcc8, fix d8f02df) — Risk: 3
   - Files: new `Triggers/CustomerInactive.php`, `Core/Runtime/InactiveSweep.php`; `Modules/WoocommerceModule.php`; `includes/YayMatik.php`; `Core/Runtime/Cleanup.php` (`ensure`); `Core/Runtime/HealthCheck.php`; TS per finding 13: `steps/triggers/customer-inactive/index.ts`, `steps/index.ts`, `packages/types/src/workflow.ts`, `packages/types/src/health.ts`, `routes/settings/HealthPanel.tsx` + test; fixture; `dev/scripts/reset-data.sh` (two hooks); `tests/Unit/ModuleRegistryTest.php`, `Core/Steps/StepContractTest.php`, `Triggers/TriggerRefsTest.php`, new `tests/Unit/Core/Runtime/InactiveSweepTest.php` (`days_of`, `window`), trigger `matches` and `sanitize` (90.5 → 91).
   - Docs: design §3 new "Inactive sweep" bullet (D2, D3), §9 row (e) and "Built"; §5 Picker sections if needed; `.claude/docs/context-php-backend.md` (trigger, sweep, `check-winback`).
   - check-winback adds: N-1 no; N and N+7 yes (a minute's margin); N+8 no; on-hold and refunded orders in the window are candidates; pending, failed and cancelled aren't; newer on-hold, refunded or paid order excludes, by account and by email; newer guest order with the same email (other case) excludes; newer pending, failed or cancelled order doesn't exclude, by account and by email; guest included; same-second orders → only the higher id; `_anonymized` skipped; no billing email skipped; two workflows same days → one run each, subject `order:<id>`; different days → only the matching one; second sweep → no new run; a new order later N days old → a new run; nothing active → no order query (`$wpdb->queries` or a `pre_` filter count); ensure schedules; health: job removed → `inactive_sweep_job` problem, fix → ok, more than a day late → problem; `fix` with no problem → 0.
   - Done: `check-winback`, `check-runs`, `check-carts` PASS; PHPUnit; `pnpm test` parity and HealthPanel.

3. ✅ **CP3 Panel + picker** (b99718a, fix 4e3bee1) — Risk: 2
   - Files: `steps/triggers/customer-inactive/index.ts` → `.tsx` (Footer); `steps/triggers/core/CartTriggerNotes.tsx` (export `Hint`); tests for the hint, the 7–730 messages and `normalize`; `StepPicker` Customers section if a test exists.
   - Docs: ROADMAP-v1.0 Editor › Trigger panels "whole number, 7–730"; Editor "Today" line if it lists triggers.
   - Done: stop hook green; `./run.sh meta-fixture` unchanged.

4. ✅ **CP4 Two templates** (6dfdfae, fix 91b7b14) — Risk: 2
   - Files: new `templates/win-back/index.ts`; `templates/index.ts`; `templates/templates.test.ts` (saves and activates through the existing `it.each`; versions linked; trigger days 90; condition rule; coupon 15% / 14 days and free shipping / 14 days, `limit_to_customer` from the defaults; subject, heading, To, greeting, sign-off, offer; blocks on their own paragraphs; `coupon` after `then`).
   - Done: stop hook green; dev registry throws nothing.

5. ✅ **CP5 Full run + docs + PR text** (6acf3f3, fixes 0ee0ebf, 38c7f11, ab7bf93, 926b9a9, 07e5a54) — Risk: 2
   - Files: `dev/scripts/check-winback.php` (batches: 500 candidates → pages of 200, 200, 100, one pending `BATCH_HOOK` action 2 minutes ahead after each full page, none after the last; 500 runs and the workflow stays active, no pause logged; turning the workflow off ends the chain. Template graphs run end to end: unsubscribed → Condition No, no coupon; else coupon + email through the mail catcher. Preview of the email: newest paid order, `sample` false; no orders → sample); `.claude/plans/dev/029-pr.md`.
   - Docs: decisions: new "M6. Win-back" (Contents row, design §11 row and its "All confirmed" line): why latest-order lookup, why the last real order, and why pending doesn't count, why a window, why per workflow per last order, why release on no start, why anonymized orders are skipped. Cost lines = Known limits above. C11 note on M6. ROADMAP-v1.0: Customer emails "Today", Template Library "Today" if it lists them, Editor "Today". ROADMAP-dev: M6 ⏳, items ⏳, M6.1 wording.
   - Run: `./run.sh check-winback`, `check-lifecycle`, `check-carts`, `check-runs`, `check-orders`, `check-status-flow`, `check-storage`, `check-late-module`; PHPUnit, phpcs, `pnpm build`, `pnpm lint`, `pnpm test`.
   - Both stores: note the value of `woocommerce_custom_orders_table_enabled`, run `check-winback` with it `yes` and `no` (`wp option update`), restore it. Orders created on the other store stay hidden while it's off, and the script removes its own. If WooCommerce refuses the switch (sync pending), log it under Deviations and add it to Jack's browser steps.
   - Docs grep over `docs/` and `.claude/docs/` for `once per customer per 90 days`, `Batching and dedupe come from the Scheduler`, `find subjects`, `except Win-back`, `Last order date`. Allowed hits: none. `trigger_claims`: only design §2, §3, §11, decisions C11, M8, M6, ROADMAP-dev M6.1 and v1.1 Backlog.
   - Symbol search: `TriggerClaimStore` (`claim`, `release`, `delete_orphans`), `claim_subject`, `release_subject`, `claims_per_workflow`, `TRIGGER_CLAIMS`, `CustomerInactive`, `InactiveSweep` (`schedule`, `reschedule`, `run`, `batch`, `candidates`, `is_last_order`, `latest_order_id`, `real_statuses`, `NOT_REAL`, `days_of`, `window`, `HOOK`, `BATCH_HOOK`), `yaymatik_customer_inactive`, `inactive_sweep_job`, `SWEEP_LATE`, `Hint`, `customerInactiveStep`, `winBackDiscount`, `winBackFreeShipping`.

## Out of scope
"Run once per order" for status triggers (v1.1, will use `trigger_claims`); resend after N days; choosing the run hour; a pre-filter of already claimed candidates (one query per batch would drop repeat lookups; only if a large store needs it).

## Browser steps for Jack
R = `SELECT id,subject,status,error FROM wp_yaymatik_runs ORDER BY id DESC LIMIT 10;` Compare each screen with its mock frame, light and dark, minus the overrides above.

1. Trigger picker → Customers shows Customer inactive with the hourglass (#picker). Panel: default 90, hint "Checked once a day" (`tInactive`). 6 and 731 show the range messages; 90.5 saves as 91.
2. Template Library → both Win-back versions, linked as versions; the free shipping one shows the shipping warning when no method allows it.
3. Use Win-back + discount; Preview → newest paid order, 15% box, Visit the shop button (#m6, desktop and mobile).
4. Set an order's date 91 days back (the order's edit screen), activate, run `yaymatik_inactive_sweep` and then the `yaymatik_inactive_sweep_batch` job it queued from Scheduled Actions → R shows `order:<id>`; Runs table "Order #N" linked, Completed, 3 steps; email arrives, coupon works.
   4b. A second customer whose last paid order is 91 days back and who has an on-hold order from today → run the sweep → no run for them.
   4c. A customer whose last real order is a refunded order 91 days back → run the sweep → a run, subject that order.
5. Run the sweep again → no new run. Unsubscribe that email, use a second order and workflow → run ends at the Condition's No path, 1 step, no coupon.
6. Settings → Health check: cancel the job in Scheduled Actions, reload → the row with Schedule again; click → gone.
7. **Posts store (not run here).** WooCommerce → Settings → Advanced → Features: sync orders until none are pending, switch to WordPress posts storage, `./run.sh check-winback`, then switch back. This is the only run of `orderby 'date ID'` and the billing email lookup on posts.

## Deviations
- CP1: the Worker also releases a claim when a queued run ends because its workflow was turned off, and the Dispatcher releases when `start_run()` throws (review findings); beyond finding 4, same reason.
- CP5: `check-carts` left 4 queued runs on every run (found in Jack's browser checks, runs 4213–4216): its last sweep also takes the `firstorder` cart. Its cleanup now removes runs by workflow and by cart, with their jobs and claims.
- CP5: **posts store not run.** `woocommerce_custom_orders_table_enabled` was `yes` before and after. WooCommerce refused `no` ("can't be changed while there are orders out of sync": 58 orders pending, data sync off, 0 `shop_order` posts), so `check-winback` passed on HPOS only. Added to the browser steps. Not worked around: syncing writes every order to `wp_posts`.
- CP5: step counts differ from the mock and the plan's browser steps: a trigger writes no step row, so a full run shows 3 and the No path 1, not 4 and 2. Browser steps corrected; the mock's Runs table frame needs updating.
- CP3: `InactiveSweep::run()` queues one `BATCH_HOOK` job per days value instead of running page 1 inline (review finding: a slow or failing value starved the others). Browser step 4 and 5: after running `yaymatik_inactive_sweep`, the batch job runs next, also from Scheduled Actions. The Worker's release needs a run with no step rows (review finding: a recovered run could email twice).
- CP2: the sweep queues the next page before it processes the current one (review finding), so a failure on one order doesn't hold back later pages.
- CP1: `check-winback`'s first run cast `WorkflowStore::create()`'s array to int, so its cleanup called `WorkflowStore::delete( 1 )` on the dev site and left row 124. Workflow 1 was already gone (run 164: "deleted before this run started", 2026-11-02); row 124 removed. Script fixed.

## Meta
- Target branch: jack/feature/m6-win-back
- Base branch: develop (8a94a41)
