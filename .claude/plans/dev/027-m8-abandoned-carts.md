# Plan: 027 — M8 Abandoned cart + checkout

## Goal
A cart or checkout left without an order starts one reminder series for its shopper, the series stops as soon as they order, and the library offers the 5 Recover carts templates.

## Rules for /wdev (from Jack's brief and CLAUDE.local.md)
- One-pass mode: all CPs of a PR in one run. After each CP: stop hook green, `/code-review high` on risk 2–3 CPs (fix findings, skip ones about earlier dev data), commit locally by path, mark ✅ + hash here ("✅ **CP2 …** (a1b2c3d)"), go on without waiting.
- Stop and ask only when: tests or lint still fail after two fix attempts; the code contradicts a decision below; an irreversible step is needed (deleting data, `git reset --hard`, a schema change outside this plan); two valid options and the plan doesn't choose. Small choices: take the simplest, log under "Deviations".
- Commits: stage by path (never `git add -A` / `.`); never `.claude/settings*.json`; no Co-Authored-By / Claude-Session lines; message `feat|fix|docs: plan 027 CPn — …`. Review fixes are new `fix:` commits, never amend or rebase. Never `git stash`.
- Never push or `gh pr create`. Plans stay uncommitted.
- Search every new symbol; delete those without a caller (a test alone is not a caller). `composer dump-autoload` after a new PHP class. `./run.sh meta-fixture` after a catalog or `yayMatikMeta` change; `localize.d.ts` with it.
- Docs grep gives an allowed-hits list; never reword decision lines to pass it. A difference from the docs updates the docs in the same commit.
- No other product's name in code or docs. Dates and schedules never in `docs/`.
- End of each PR: one report and stop. Per CP: hash, files, tests, review findings and fixes, deviations. Then: unused code outside the task, browser steps for Jack (no Chrome here), PR text path.
- Time budget: PR A done Tue Nov 3 morning, PR B done Tue Nov 3 evening. Prefer the smallest change that meets each decision.

## What research changed (read this first)
Each item adjusts the brief. Say so in the report.

1. **D5 as written skips both carts.** Claims run at shutdown, after the whole sweep. Two carts with one email marked in the same sweep each see the other's `abandoned_at`, so neither starts. **Changed by Jack after review:** D5 reads a new column, `series_at`, not `abandoned_at`. `CartTrigger::claim()` sets it when it lets a cart through, and claim runs only when an active workflow exists for the trigger (`Dispatcher.php:173`). A cart blocks when another cart with the email is not ordered and has `series_at` in the last 7 days. No id tie-break: events are claimed in order, so the older cart of a sweep claims first and blocks the next. A cart abandoned with no matching workflow no longer blocks. This replaces the first version of this fix (`earlier_series`, compare `abandoned_at`).
2. **D9's `WC_Customer` loses the email.** `WC_Customer::get_billing_email()` reads `billing_email` user meta only (`class-wc-customer-data-store.php:175-186` sets `email`, not billing). A logged-in shopper who never checked out has an empty one, so `{{ customer.email }}` is empty and the email fails. The cart row always holds the account email (`CartTracker::record`). Fix: one adapter, `CartCustomer`, for every cart: email and first name always from the row; other fields from `WC_Customer` when `user_id` > 0 and the user exists, else `''`. This also keeps To, unsubscribe and D5 on the same address.
3. **`find_paid_orders` (asked in D9): no account lookup.** It's `wc_get_orders` with `customer_id` or `billing_email` only. `paid_ids` adds `customer_id` from `get_id()`: the adapter returns the row's `user_id` (logged-in, never found by email) or 0 for guests, so guests go by email only.
4. **CP order.** `Worker::start()` loads every trigger ref and skips the run when one is gone. Without the `cart` and `customer: cart:<id>` loaders, no cart run gets past its start. So the `cart` data item (plain fields), `SavedCart`, `CartCustomer` and both loaders move from CP4 to CP2. CP4 keeps blocks, Preview, presenter, Validator.
5. **D7 also needs `Worker::start()`.** A queued run reaches `start()` before the Executor loop, and a gone cart would end `skipped`, not `cancelled`. The stop check goes there too, before the ref loads.
6. **D12 and Update.** `Validator::errors()` also guards Apply, so a plain issue would block editing an active workflow once tracking is off, with a wrong message. Fix: the issue is reported only when `$workflow['status']` is set and isn't `active`. Activate and `issues()` pass the status; Apply doesn't. TS `validateWorkflow()` doesn't get this rule (it comes from the server through `withServerIssues`), or the dev template registry and `templates.test.ts` would throw with tracking off.
7. **Meta goes stale.** `yayMatikMeta` is set once per page load and Settings lives in the same app, so turning tracking on then going back would still show the warning and badge. `useSaveSettings` writes the saved values into `window.yayMatikMeta.wc`; readers read it when they render, never at module level. The panel note needs the minutes, so meta carries `wc.cartTracking` and `wc.abandonMinutes`.
8. **Triggers have no picker section.** `category()` is on `Action` only and `StepPicker` draws triggers as one list. Add `Trigger::category(): string` (default `''`), `OrderTrigger` → `order`, `CartTrigger` → `cart`, and sections for triggers too. Labels: `cart` "Carts".
9. **Icons.** `StepIcons` has no `cart`: use `shopping-cart` and `credit-card`.
10. **MySQL SET order.** A single-table `UPDATE` reads already-assigned columns, so `abandoned_at = …, abandon_trigger = IF(abandoned_at IS NULL, …)` sees the new value. The sweep decides in PHP from the row it read and writes plain values.
11. **"Run detail links the order" had no CP.** `RunPresenter::detail()` gets it in CP4, `RunDetailSheet` in B1. The reason sits on the step row when a wait was cancelled, so the link is its own line, shown either way.
12. **Erase keeps its own reason.** `PersonalData::erase` already cancels waiting runs by subject ("The customer's personal data was erased."), before it deletes carts. No new code; the reason is more exact than D7's.
13. **`rekey()` and `bind()` also delete rows.** Not in D7's list. Their runs stop through the Trigger hook at the next step, "The cart was emptied or deleted." No eager code.
14. **Check script grows per CP**, not only in CP5, so risk 2–3 CPs have a real done-check (as in plan 026). CP5 adds what's left and runs everything.

## Decisions against docs and code
| # | Result | Note |
|---|---|---|
| D1 carts columns, status (+ `series_at` for D5) | Docs silent | Design §2 updated in CP1 |
| D2 Sweep | Differs (ROADMAP-dev M8.1, design §3 "Scheduled and query triggers") | Updated in CP1 |
| D3 No `trigger_claims` | Differs (M8.1, C11, §3) | M8.1, C11 note, §3, M6.1, V11.3, build-order line updated in CP1 |
| D4 Event, triggers | Docs silent; follows design §4 subject form | Icons per finding 9 |
| D5 One series per email | Follows follow-up "Pause per email address" | `series_at` per finding 1; decisions M8 entry |
| D6 Series goes on | Differs (follow-up "When a cart turns active again … cancel") | ROADMAP-dev updated in CP3 |
| D7 Stop on order | Follows M8.4, follow-ups, mock #rundetail | Findings 5, 11, 12, 13 |
| D8 Cart data | Follows design §4, mock #vars | Keys now fixed; mock note for Jack |
| D9 Customer | Follows follow-up "never an account found by email" | Differs from the brief's `WC_Customer` branch: findings 2, 3 |
| D10 Snapshot prices | Differs (follow-up "live name and price") | ROADMAP-dev updated in CP4 |
| D11 Preview | Differs (design §6 "latest paid order"); follows ROADMAP-v1.0 "recent order or cart" | Design §6 updated in CP4 |
| D12 Activate issue | Follows M1.10, mock #editor #msgs | Finding 6 |
| D13 Subject label | Follows mock #overview | — |
| D14 Docs | — | Listed per CP |
| Template timings | Follow ROADMAP-v1.0 Template details | 1 h, 24 h, 72 h from the start |

## Code facts
- `Schema::DB_VERSION` = '5'; carts already has `KEY status (status,last_activity_at)`, `KEY email`. `runs` has no `subject` index: match with `status = 'waiting' AND subject IN (…)`, which uses `KEY status`.
- `CartStore::upsert` updates only the fields passed; `touch` takes any fields; `link_order` is raw SQL. `record()` re-reads the row after `bind_restored`.
- `view()` throttle is 5 min, `abandon_minutes` min 15: a view of an abandoned row always writes.
- `RestoreCart` refuses only `ORDERED` (line 65): abandoned rows restore. `bind()` allows any status but ordered.
- `Dispatcher::dispatch`: `active_for_trigger` first, then `claim`, `context`, `resolved_refs`. `Worker::current()` is null outside a run, so sweep events have no parent. `CEILING` 1000 / 60 s is out of reach at 200 per sweep.
- `Scope` only reads objects from the resolver (`is_object`), so a row needs a class. `RefResolver::default_loaders()` is private.
- `CustomerData` needs `get_billing_email/first_name/last_name/phone/country`, `get_id`. `is_first_order` for a cart means no paid order; for another non-order, exactly 1.
- `EmailPreview::values` picks data by `OrderData::ID` in provides. `SendEmail` reads To through `SendEmail::recipient`.
- TS: `withServerIssues` merges saved server issues; `activateIssues` is empty for active workflows; default `QueryClient` has staleTime 0, so workflow issues refetch on mount.
- `ViewKeeper` already brings the step whose panel opens into view; `openPanel(id, true)` opens one for a new step.
- `@xyflow/react` 12: `panOnScroll`, `zoomOnScroll`, `zoomOnPinch`, `zoomActivationKeyCode`.

## Design

### Status and row (D1)
`CartStore::ABANDONED = 'abandoned'`. `abandoned_at datetime NULL`, `abandon_trigger varchar(30) NOT NULL DEFAULT ''`, `series_at datetime NULL` (D5). `DB_VERSION` '6'. `reset_cycle` clears all three.
Activity on an abandoned row sets `status = active`, keeping `abandoned_at`:
- `record()`: `$fields['status'] = ACTIVE` when `$row['status'] === ABANDONED`.
- `view()`: same in its `touch`.
- `capture()`: goes through `record()` first, so nothing extra.
- `link_order`: `status = IF(status = 'abandoned', 'active', status)`.

Ordered rows never change here; a new cycle still only comes from `reset_cycle`.

### Sweep (D2, D3)
New `Core/Cart/CartSweep`: `HOOK = 'yaymatik_cart_sweep'`, `boot()`, `run()`, `schedule()`.
- `YayMatik.php` boots it next to `Cleanup`. `Cleanup::ensure()` adds a second check: `as_has_scheduled_action( CartSweep::HOOK, [], GROUP )` else `CartSweep::schedule()` (recurring 300 s, unique, first run in 300 s). `HealthCheck` unchanged.
- `run()`: returns when `cart_tracking` is off. Loop until 20 s are used: `CartStore::due( $before, $after, 200 )` → `SELECT id, status, last_activity_at, checkout_at, abandoned_at WHERE status = 'active' AND email <> '' AND items NOT IN ('', '[]') AND last_activity_at <= %s AND last_activity_at > %s ORDER BY last_activity_at, id LIMIT 200`. One batch per run is enough unless it's full: stop when fewer than 200 came back.
- Each row: `CartStore::mark_abandoned( $id, $last_activity, $first ? kind : '' )`: `UPDATE … WHERE id AND status = 'active' AND last_activity_at = %s`, with `abandoned_at` and `abandon_trigger` set only when `$first` (`abandoned_at` was NULL). True only when one row changed.
- `CartSweep::kind( array $row ): string` → `checkout_at` set ? `checkout_abandoned` : `cart_abandoned` (pure, unit tested).
- First and marked → `do_action( 'yaymatik_cart_abandoned', $id )`.

### Triggers and claim (D4, D5)
`Triggers/CartTrigger` (abstract, `Trigger`): `category()` `cart`, `provides()` `[ CartData::ID, CustomerData::ID ]`, `fields()` `[]`, `subject()` `cart:<id>`, args `[ 'cart_id' => int ]`.
- `listen`: `add_action( 'yaymatik_cart_abandoned', … )`; reads the row; `$emit` only when `abandon_trigger === static::ID`.
- `context()`: `[ cart => SavedCart, customer => CartCustomer ]`, null when gone. `refs()`: `Ref( cart, id )`, `Ref( customer, 'cart:<id>' )`.
- `claim()`: false when the row is gone, or `UnsubscribeStore::has( email )`, or `CartStore::series_taken( $email, $id, $since )` (`WHERE email = %s AND id <> %d AND status <> 'ordered' AND series_at >= %s`, 7 days). Otherwise `CartStore::mark_series( $id )` sets `series_at` = now, and it returns true.
- `CartAbandoned` (`cart_abandoned`, `shopping-cart`, "Cart abandoned", description "A shopper leaves products in the cart", summary "When a cart is abandoned"), `CheckoutAbandoned` (`checkout_abandoned`, `credit-card`, "Checkout abandoned", "A shopper leaves the checkout page without ordering", "When a checkout is abandoned").

### Cart data (D8, D9)
- `Core/Cart/SavedCart`: wraps a row. `find( int $id ): ?self`, `inert(): self`, `is_inert()`, `id()`, `email()`, `first_name()`, `lines()` (JSON decoded, `[]` on bad JSON), `total()`, `currency()`, `last_activity()`, `created_at()`.
- `Core/Cart/CartCustomer`: `__construct( SavedCart, ?\WC_Customer )`; per finding 2. `get_id()` = the row's `user_id` when the account exists, else 0.
- `DataItems/CartData` (`cart`, icon `shopping-cart`): email "Email", first_name "First name", total "Cart total" (money like `order.total`, `wc_price` with the cart's currency), item_count "Number of products" (sum of quantities), product_names "Product names", last_activity "Last activity" (value `Y-m-d`, display date and time via `wp_date`, store timezone), restore_url "Restore link" (`conditional` false), blocks in CP4. `sample()`.
- `CartData::names( array $lines ): string`: `A` / `A and B` / `A, B and N more`; pure, translated with `_n`.
- `RefResolver`: `cart` → `SavedCart::find`; customer kind `cart` → `CartCustomer`. `default_loaders()` public for Preview. `CustomerData::description()` neutral: "The person behind the event, from their billing details. Works for guests too."
- Register `CartData` and the triggers in `WoocommerceModule`.

### Stop on order (D6, D7)
- **Trigger hook:** `Trigger::stop_reason( string $subject ): ?string` default null. `CartTrigger`: row gone → `Cancellation::cart_gone()` ("The cart was emptied or deleted."); `ordered` → `Cancellation::order_placed( $row['order_id'] )` ("The shopper placed order #N.", order number, falling back to the id). Active or abandoned → null (D6).
- **Executor:** after each `is_active()` check (loop top, and after saving a wait): `$reason = $this->stop_reason( $run )`; `cancel()` takes `$reason`. `stop_reason` gets the step through `$this->steps` by `trigger_id`; a non-Trigger → null.
- **Worker::start:** after the `is_active` check, same reason → `end( cancelled, $reason )`; use `$this->steps ?? [ StepRegistry::class, 'get' ]`.
- **Eager:** `Cancellation::carts( int[] $ids, string $reason ): int` → `RunStore::waiting_ids_for_subjects( subjects )`, then `RunStore::cancel_waiting` + `RunStepStore::cancel_waiting`.
- `CartStore::mark_ordered_by_order( $order_id )`, `mark_ordered_by_email( $email, $before, $order_id )` return `int[]` ids marked: select ids, then `UPDATE … WHERE id IN (…) AND status …`. By email covers `active` and `abandoned` and sets `order_id`. `CartTracker::order_status_changed` cancels with `order_placed` (each path).
- `CartTracker::emptied()`: after the delete, `Cancellation::carts( [ id ], cart_gone() )`.
- `Cleanup::run`: `CartStore::inactive_ids_before( $before, $limit )` → cancel → `delete_ids( $ids )`; replaces `delete_inactive_before`, same batches and log line on failure.

### Blocks (D10)
New `Core/Email/CartItems`: `render( SavedCart $cart, bool $html ): string`, one line (wpautop). Table: image (64px, `ProductGrid` look: `#f4f4f5` square, `margin:0`, explicit padding on every `td`), name + "Qty: N", `line_total` right aligned; last row "Cart total" + `total`. All money from the snapshot with `wc_price( …, [ 'currency' => … ] )`. Live name, image, link from `wc_get_product( variation_id ?: product_id )` when it exists and is published; else snapshot name, placeholder image, no link. Text form: `Name × N — price` per line, then the total. No lines → `''`.
`cart.items` "Cart items" and `cart.restore_button` "Restore my cart button": `block` fields shaped like `OrderData::grid_field`. Button: bulletproof `<table>` + `<a>`, inline styles, background `get_option( 'woocommerce_email_base_color' )` as in `GenerateCoupon`'s box, text "Restore my cart". Inert cart → `href="#"`, and `restore_url` → `#`. `sample()`: lines from `ProductGrid::newest()` (up to 3; made-up names when none), total, inert button.

### Preview (D11)
`EmailPreview::values`: `CartData::ID` in provides → `CartStore::newest_for_preview()` (email and items, any status, newest id) → refs from `resolved_refs( [ 'cart_id' => id ] )`, scope with a `RefResolver` whose cart loader returns `SavedCart::find()->inert()`, customer built on the same. None → existing `SampleValues`. `order` stays null, so the dialog reads "Nothing is sent".

### Activate issue (D12)
`Trigger::setup_issue(): ?string` default null; `CartTrigger` returns the message when tracking is off: "Turn on cart tracking before activating. Cart abandoned never starts while it's off." (Checkout abandoned for that trigger; `$this->label()`).
`Validator::errors()`: when `isset( $workflow['status'] ) && 'active' !== $workflow['status']` and the trigger has a setup issue → `[ 'code' => 'cart_tracking_off', 'message', 'nodeId' => trigger id ]`. `issues()`, `activate_workflow` (both calls) pass `status`. Apply and Preview don't. Meta: `wc.cartTracking`, `wc.abandonMinutes` in `AdminPage`. `Trigger::validate()` stays free of this, so `Dispatcher` isn't affected.

### Presenter (D13, D7)
`RunPresenter::subject`: `cart` → row's email, else "Cart #id"; `subjectUrl` null. `detail()` adds `stoppedByOrderUrl`: subject is a cart, status cancelled, row `order_id` > 0 and the order exists → `get_edit_order_url()`, else null. `item()` doesn't.

### Templates
`templates/abandoned-cart/index.ts` (3) and `templates/abandoned-checkout/index.ts` (2), like `cross-sell-upsell`: local `graph()`, all steps in one line, step ids `trigger`, `wait-1`, `email-1`, `wait-2`, `email-2`, `wait-3`, `coupon`, `email-3`. Category `recover_carts`; workflow `abandoned_cart` / `abandoned_checkout`; `createdAt` as today's convention. Coupon `{ ...generateCouponStep.defaults(), amount: 10, expiry_days: 7 }`. Greeting `Hi {{ customer.first_name | default: "there" }},`, sign-off `See you soon,\n{{ site.name }}`, To `{{ customer.email }}`.

| Template id | Name | Steps | Description |
|---|---|---|---|
| cart-reminder | Cart reminder | Wait 1 h → A | "An hour after a cart is abandoned, email the shopper their cart. Stops as soon as they order." |
| cart-series | Cart 3-email series | Wait 1 h → A, Wait 23 h → B, Wait 2 days → C | "Three reminders with the shopper's cart: after 1 hour, 1 day and 3 days. Stops as soon as they order." |
| cart-series-coupon | Cart 3-email series + coupon | Wait 1 h → A, Wait 23 h → B, Wait 2 days → coupon → C2 | "Three reminders with the shopper's cart; the last adds a 10% off coupon valid for 7 days. Stops as soon as they order." |
| checkout-reminder | Checkout reminder | Wait 1 h → D | "An hour after a checkout is abandoned, email the shopper the order they started. Stops as soon as they order." |
| checkout-reminder-coupon | Checkout reminder + coupon | Wait 1 h → D, Wait 23 h → coupon → E | "A reminder after 1 hour, then a 10% off coupon valid for 7 days after 1 day. Stops as soon as they order." |

| Email | Subject | Heading | Body between greeting and sign-off |
|---|---|---|---|
| A | You left something in your cart | Your cart is saved | We saved the items you left at {{ site.name }}: · `{{ cart.items }}` · `{{ cart.restore_button }}` |
| B | Still thinking it over? | Your cart is waiting | Your cart is still saved. Pick up where you left off: · items · button |
| C | Last reminder about your cart | Your cart is still here | This is our last reminder. Your items are one click away: · items · button |
| C2 | {{ steps.coupon.amount }} off the cart you left | A little something to help you decide | Use this coupon for {{ steps.coupon.amount }} off your order: · `{{ steps.coupon.box }}` · items · button |
| D | Did something go wrong at checkout? | Finish your order | You were almost done. Your order isn't placed yet, and your items are saved: · items · button · If something went wrong, reply to this email and we'll help. |
| E | {{ steps.coupon.amount }} off to finish your order | Your order is still waiting | Use this coupon for {{ steps.coupon.amount }} off your order: · coupon box · items · button |

## Checkpoints

### PR A — `jack/feature/m8a-abandoned-carts` from develop cfb9337

0. ✅ **CP0 Docs: M7 done** (4d1bf9f) — Risk: 1
   - Files: `docs/ROADMAP-dev.md`: phase table M7 "✅ Done"; Status "✅ Done. Merged into `develop` (PR #30, PR #31, `cfb9337`)."; M7.6, M7.7 ✅.
   - Tests: none. Done: `grep -n "⏳ \*\*M7" docs/ROADMAP-dev.md` → none.

1. ✅ **CP1 Schema v6 + statuses + sweep job** (38597ad) — Risk: 3
   - Files: `Core/Storage/Schema.php`; `Core/Storage/CartStore.php` (`ABANDONED`, `due`, `mark_abandoned`, `mark_ordered_by_email` covers abandoned + order id, `link_order`, `reset_cycle`); `Core/Cart/CartTracker.php` (activity, paid order id); new `Core/Cart/CartSweep.php`; `Core/Runtime/Cleanup.php` (`ensure`); `YayMatik.php`; `dev/scripts/reset-data.sh` (unschedule the sweep); `dev/scripts/check-carts.php`; new `tests/Unit/Core/Cart/CartSweepTest.php`.
   - Docs: design §2 carts row (columns including `series_at`, statuses active / abandoned / ordered), §3 new bullet "Abandon sweep" replacing the `trigger_claims` wording in "Scheduled and query triggers" for M8; ROADMAP-dev M8.1 "Abandon sweep (no claims)", M6.1 gains the `trigger_claims` table and store, V11.3 reuses M6.1, build-order line 18 (M6 reuses the sweep's pattern); C11 note: claims built in M6; M8 cart first-abandon claim. Decisions: new entry "M8. Abandoned carts" (contents row, §11 row) with claim = cart cycle.
   - check-carts adds: sweep marks a due row; ignores no email / no items / too new / older than the 24 h window / non-active; tracking off does nothing; kind by `checkout_at`; second abandon keeps `abandoned_at`, `abandon_trigger` and fires no action (count with `did_action`); activity (record, view, link_order) → active with `abandoned_at` kept; a concurrent activity (`last_activity_at` changed after read) → not marked; `reset_cycle` clears `abandoned_at` and `abandon_trigger` (and `series_at`); by-email marks abandoned + sets `order_id`.
   - Tests: `CartSweep::kind`.
   - Done: `./run.sh check-carts` all PASS; `wp option get yaymatik_db_version` = 6.

2. ✅ **CP2 Triggers, claim, cart data item** (de2c76c) — Risk: 2
   - Files: new `Triggers/CartTrigger.php`, `CartAbandoned.php`, `CheckoutAbandoned.php`, `DataItems/CartData.php` (plain fields), `Core/Cart/SavedCart.php`, `CartCustomer.php`; `Core/Steps/Trigger.php` (`category`, in `client_meta`), `Triggers/OrderTrigger.php`; `Modules/WoocommerceModule.php`; `Core/Data/RefResolver.php`; `Core/Storage/CartStore.php` (`series_taken`, `mark_series`); `DataItems/CustomerData.php` (description, docblock types); TS: `steps/triggers/cart-abandoned/index.ts`, `checkout-abandoned/index.ts` (`defineStep({ id, kind })`), `steps/index.ts`, `packages/types` `StepId`, `localize.d.ts` category; fixture; `dev/scripts/check-carts.php`; `tests/Unit/DataItems/CartDataTest.php` (names, item count), `CustomerDataTest` (adapter: guest looks up by email only; user by id + row email; never empty email).
   - Docs: design §1 `DataItems`, §4 cart entity; `.claude/docs/context-php-backend.md` key paths.
   - check-carts adds: active workflow per trigger: cart vs checkout kind starts only its own (never both); `cart:<id>` subject; refs; unsubscribed skipped; one series per email (two carts in one sweep start exactly one, and it's the older; an earlier cart abandoned with no matching workflow doesn't block; an ordered cart's series or an 8-day-old series doesn't block; `reset_cycle` clears `series_at`); logged-in customer with empty billing email still resolves `customer.email`; no workflow → no run.
   - Done: check-carts PASS; `pnpm test` parity green.

3. ✅ **CP3 Stop on order** (5e1443b) — Risk: 3
   - Files: `Core/Steps/Trigger.php` (`stop_reason`), `Triggers/CartTrigger.php`; `Core/Runtime/Cancellation.php` (`carts`, `order_placed`, `cart_gone`), `Executor.php`, `Worker.php`, `Cleanup.php`; `Core/Storage/CartStore.php` (ids returned, `inactive_ids_before`, `delete_ids`), `RunStore.php` (`waiting_ids_for_subjects`); `Core/Cart/CartTracker.php`; `dev/scripts/check-carts.php`; `tests/Unit/Core/Runtime/ExecutorTest.php`, `WorkerTest.php` (fake trigger: stops before a step, after saving a wait, before the first step; other triggers untouched).
   - Docs: design §3 "Stop on order"; ROADMAP-dev M8.4 note, follow-up D6 replaced ("A shopper who comes back and leaves again: the series goes on; only an order or a gone cart stops it"); decisions M8: series goes on when the shopper returns, one series per email.
   - check-carts adds: paid by order → waiting run cancelled at once with the order number; paid by email → same on abandoned row, `order_id` set; 0-total order: own row only; emptied → cancelled "emptied or deleted"; cleanup → same; returning shopper (active again) → run still waiting; queued run + ordered cart → Cancelled at start; running run → stops before the next step; another cart's runs untouched.
   - Done: check-carts, `check-runs` PASS; PHPUnit green.

4. ✅ **CP4 Blocks, Preview, presenter, Validator, meta** (6a6b494) — Risk: 2
   - Files: new `Core/Email/CartItems.php`; `DataItems/CartData.php` (`items`, `restore_button`, `restore_url`, `sample`); `Core/Cart/SavedCart.php` (`inert`); `Core/Email/EmailPreview.php`; `Core/Storage/CartStore.php` (`newest_for_preview`); `Core/Data/RefResolver.php` (public `default_loaders`); `Core/Runtime/RunPresenter.php`; `Core/Steps/Trigger.php` (`setup_issue`), `Triggers/CartTrigger.php`; `Core/Definition/Validator.php`; `Engine/API/RestAPI.php`; `Engine/BEPages/AdminPage.php`; `localize.d.ts`, `packages/types` (`WorkflowIssueCode`, run `stoppedByOrderUrl`); fixture; `dev/scripts/check-carts.php`; `tests/Unit/Core/Definition/ValidatorTest.php` (issue when inactive and off; none when active, on, or no status), `RunPresenterTest` if present.
   - Docs: design §6 Tier 2 (cart items) and Preview line; ROADMAP-dev follow-up D10 replaced ("Emails show the price, line total, total and currency the shopper saw; live name, image and link; a gone product shows its snapshot name without a link"); decisions M8: snapshot prices.
   - check-carts adds: `cart.items` shows snapshot money after the product's price changes, live name, gone product without a link, total row; one line (no newline); `restore_url` verifies through `RestoreLink`; preview render: real cart, button `href="#"`, `sample` false; no carts → `sample` true; subject label email / "Cart #id"; order url present only when stopped by an order.
   - Done: check-carts, `check-orders` PASS; send one real series email through the mail catcher (`check-carts` prints the HTML path).

5. ✅ **CP5 Full run + docs + PR text** (76119fa, review fixes 0f53501, 731b4ac) — Risk: 2
   - Files: `dev/scripts/check-carts.{sh,php}` (listing text, anything left); ROADMAP-dev M8 backend ⏳ (M8.1–M8.5), M8.7 → "Fixed note in the trigger panel, no setting", trigger_claims moved, remaining follow-ups cleaned; `.claude/docs/context-php-backend.md` (CartSweep, CartItems, triggers, data item, `check-carts` scope); `.claude/plans/dev/027-pr-a.md`.
   - Run: `./run.sh check-carts`, `check-runs`, `check-orders`, `check-status-flow`, `check-storage`, `check-late-module`; PHPUnit, phpcs, `pnpm build`, `pnpm test`.
   - Docs grep over `docs/` and `.claude/docs/` for `trigger_claims`, `live product name`, `turns active again`, `Exit condition setting`. Allowed hits: design §3 Scheduled line (M6); C11 decision and note; design §11 C11 row; ROADMAP-dev M6.1; the old lines nowhere else.
   - Symbol search for everything new in PR A.

### PR B — `jack/feature/m8b-cart-ui` from develop e3df6e8 (PR A merged as #32; not stacked)
Build to YayMatik Pages v3 (https://claude.ai/artifact/R6XzCEZqqyVHBe7uaknMoS) sections #picker #triggers #vars #emails #editor #msgs #library #preview #overview #rundetail. Overrides: the D5 hint line; YayUiKits components only; colours from theme tokens, no `dark:`. Authority: this plan, docs, then the mock.

B1. ✅ **Panels, issue, badge, Before you start, run link** (001440d) — Risk: 2
   - Files: `steps/triggers/cart-abandoned/index.tsx`, `checkout-abandoned/index.tsx` with `Footer`; new `steps/triggers/core/CartTriggerNotes.tsx`; `pages/editor/StepPicker.tsx` (trigger sections, `cart` label "Carts", no heading for a step without a category), `pages/editor/IssueBanner.tsx` and `pages/overview/WorkflowOverviewPage.tsx` (Open Settings on `cart_tracking_off`); `pages/list/library/*` card badge; `pages/template-preview/TemplatePreviewPage.tsx`; `templates/core/meta.ts` (`needsCartTracking( graph )`), `templates/index.ts`; `pages/overview/RunDetailSheet.tsx` ("View order" link when `stoppedByOrderUrl`); `routes/settings/api/settings.query.ts` (writes `wc.cartTracking`, `wc.abandonMinutes` on save); `steps/core/fields/StepForm` if the Footer needs it; `model/validation.ts` (`withServerIssues` lets `cart_tracking_off` through, for the activate issue and the draft issue an Update returns, 731b4ac).
   - Panel text, in order: "A cart counts as abandoned after {N} minutes without activity." · never-both line (Cart: "Carts that reached checkout start Checkout abandoned instead, never this trigger." / Checkout: "Only carts that reached checkout with an email. They never start Cart abandoned.") · "Runs stop as soon as the shopper places an order." · "Shoppers who unsubscribe get no more reminders. A shopper who already got a reminder this week for another cart is skipped." · tracking off: warning "Cart tracking is off, so this trigger never starts." with a link to Settings (`Alert` like `FreeShippingWarning`).
   - Before you start: "Turn on cart tracking" with a link to Settings, hidden when on. Library badge "Needs cart tracking".
   - Tests: `needsCartTracking` on/off; `CartTriggerNotes` (minutes, warning, both texts); `settings.query` writes meta; `RunDetailSheet.test` link; `StepPicker` sections if a test exists.
   - Done: stop hook green; a symbol search.

B2. ✅ **The 5 templates** (908bee7) — Risk: 2
   - Files: new `templates/abandoned-cart/index.ts`, `abandoned-checkout/index.ts`; `templates/index.ts`; `templates/templates.test.ts`.
   - Tests: each saves and activates as is (existing `it.each`); versions linked (3 + 2); timings (sum of waits 1 h, 24 h, 72 h; 1 h, 24 h); coupon 10 / 7 right before its email; every email has `{{ cart.items }}` and `{{ cart.restore_button }}` on their own paragraphs, To `{{ customer.email }}`, the default-"there" greeting, the sign-off; subjects and headings per table.
   - Done: `pnpm test`; `./run.sh meta-fixture` unchanged.

B3. ✅ **Editor canvas scroll** (7d71419) — Risk: 1
   - Files: `pages/editor/WorkflowCanvas.tsx`: `panOnScroll`, `zoomOnScroll={false}`, `zoomOnPinch`, `zoomActivationKeyCode={['Meta', 'Control']}`. A new step comes into view through `ViewKeeper` when its panel opens; check this, and add a call only if a gap shows. `docs/ROADMAP-dev.md`: M7 follow-up removed (done).
   - Tests: none new (ReactFlow behaviour); browser steps.
   - Done: build, lint.

B4. ✅ **Regression + docs + PR text** (879ef49, final review fix 950df8d) — Risk: 1
   - `pnpm build`, `pnpm lint`, `pnpm test`; list, editor, runs, Settings untouched by diff review; ROADMAP-dev M8.6–M8.8 and Templates ⏳; ROADMAP-v1.0 Template Library / Editor "Today" lines; design §5 note on `Trigger::category`; `context-dev-convention.md` only if a rule changed; mock note for Jack: final email text and Cart keys are now set (`.claude/plans/dev/027-pr-b.md` lists them); `.claude/plans/dev/027-pr-b.md`.

## Browser steps for Jack
Q = `SELECT id,email,status,checkout_at,abandoned_at,abandon_trigger,order_id,last_activity_at FROM wp_yaymatik_carts ORDER BY id DESC;` · R = `SELECT id,subject,status,error FROM wp_yaymatik_runs ORDER BY id DESC LIMIT 10;` · Abandon now = `UPDATE wp_yaymatik_carts SET last_activity_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE id = N;`, then Tools → Scheduled Actions → `yaymatik_cart_sweep` → Run.

**PR A** (tracking on via Settings; activate workflows by REST or SQL until PR B)
1. Load a page → `yaymatik_db_version` 6. Delete `yaymatik_cleanup_checked_at`, reload → Scheduled Actions shows `yaymatik_cart_sweep` every 5 min.
2. Guest cart, no email; Abandon now → Q stays active.
3. Logged-in customer who never checked out: cart; Abandon now → `abandoned`, `cart_abandoned`; R a queued run `cart:N`; it sends with the account email.
4. Guest at classic checkout with an email; Abandon now → `checkout_abandoned`. Same on block checkout.
5. Shopper returns (cart change) → Q active, `abandoned_at` kept; run still waiting. Abandon now → abandoned; R no new run.
6. Place the order from that cart → Q ordered; R Cancelled "The shopper placed order #N." at once.
7. Cart abandoned with a waiting run, then empty it → R Cancelled "The cart was emptied or deleted."
8. Second cart with the same email within 7 days; Abandon now → no second run. Unsubscribed email → no run.
9. Preview email on a cart workflow → real cart, Restore button goes to `#`. No carts → "Sample data".
10. Real email in the mail catcher: items table, prices, total, Restore link refills the cart. Open in Gmail or Outlook web if you can.

**PR B** (compare each screen with its mock frame, light and dark)
1. Picker: WooCommerce → Order and Carts sections; icons.
2. Both trigger panels with tracking on and off; the minutes follow Settings without a reload.
3. Use Cart 3-email series + coupon with tracking off: Before you start, library badge; Activate → blocked, issue bar + Open Settings, overview bar too.
4. Turn tracking on in Settings, go back without a reload → badge, warning, issue gone; Activate works.
5. Active workflow, tracking off → stays active, Update still works.
6. Run detail stopped by an order → Cancelled, reason, View order link. Runs table subject = email; gone cart "Cart #id".
7. Insert variable in Message → Cart fields; Blocks has Cart items and the button; not offered in Subject.
8. All 5 templates: What it does, Other versions, Preview each email (Desktop / Mobile).
9. Canvas: wheel / trackpad pans; Ctrl/Cmd + wheel and pinch zoom; a step added below the view comes into view.

## Known limits
- A claim with no run started (no live version, rate limit) still sets series_at and blocks that email for 7 days.
- A cart abandoned before its workflow was activated never starts it: the event fires only on the first abandon of a cart cycle (D3). On purpose: activating a workflow never emails old carts.
- Events fire at shutdown of the sweep: a crash between marking and flush loses that cart's series.
- A queued or running run of an old cycle whose cart was ordered and then changed (new cycle, status active) isn't stopped by the hook. Waiting runs were already cancelled at the order; this needs a slice boundary in the same few minutes.
- `cart.items` line totals are before coupons and the cart total is after them, as the cart page shows it.
- The sweep adds 288 Action Scheduler rows a day, even with tracking off; Action Scheduler's own retention removes them.
- Field descriptions in the mock's Cart list aren't in the data item contract: Insert variable shows labels only.

## Out of Scope
`trigger_claims` (M6); consent / opt-in mode; admin list of carts; revenue reports; confirm page before restore; coupons in the eraser; health check rows for the sweep job (`ensure()` covers a missing job; `jobs_late` covers a late one); `abandoned_at` in the personal data export.

## Deviations
- CP1, sweep: one batch of 200 per sweep, no 20 s loop. The Design's loop could put over 1000 events into one shutdown after a backlog and pause the workflow at the Dispatcher's ceiling; Code facts already count on 200 per sweep. Found in review.
- CP1, sweep window: 24 h counted back from the abandon cutoff (`before`), not from now, or `abandon_minutes` = 1440 would leave an empty window.
- CP2, `claim()` also refuses a cart ordered between the sweep and its shutdown (review), so no cancelled run shows up for it.
- CP2, `Trigger::category()` is left out of the catalog when empty, so the TS key stays optional. `categoryLabels` in `StepPicker` gets `cart` now because its type requires it; the sections themselves stay in B1.
- CP2, fixture: the dev site's PayPal gateway removed by hand after `meta-fixture`.
- CP3, tests: `DispatcherWorkerTest` holds the Worker cases (there is no `WorkerTest.php`); new `tests/Support/FakeTrigger.php`.
- CP3 and final review, `emptied()` on a row kept for its unpaid order: cancels its waiting runs and sets its items to `[]`, so the sweep skips it; `stop_reason()` treats `[]` as gone. The row must stay for the payment, the reminders must not.
- CP4 and final review, `stoppedByOrderUrl` also needs the cart `ordered` and the order created before the run finished: a cart ordered after its run stopped for another reason shows no link. Not read from the reason text, which is translated.
- CP4, a published variation of an unpublished parent counts as gone (review): its link would go to the parent's page.
- CP4, `SavedCart::price()` instead of `CartData::price()`: `CartItems` needs it too. `EmailPreview` overrides only the `cart` loader: the customer reads just the row's email and name, never the link.
- CP4, `meta-fixture.sh` fixes `wc.cartTracking` false and `wc.abandonMinutes` 60, so the fixture doesn't follow the dev site.
- **For B1 (finding 6 is short of this):** `withServerIssues` in `model/validation.ts` keeps only `invalid_config`, so the editor drops `cart_tracking_off` from the banner. Let that code through and add the file to B1. Found in CP4 review; no PR A change, as nothing draws it yet.
- CP5, docs grep: one more allowed hit, the M8 decision line "No `trigger_claims` table".
- After PR A report (Jack): `customer.is_first_order` on a cart is true with no paid order, not exactly one. Replaces the Known limit.
- After PR A report (Jack), finding 6: `live_graph` in `Validator::errors()`: Update reports `cart_tracking_off` when the draft's trigger differs from the live one and is a cart trigger; the same trigger stays exempt. `issues()` shows it on the draft.
- CP1, `mark_abandoned` leaves `updated_at` alone: the capture limit (`sessions_with_email`) counts it as shopper activity.
- B1, panel warning position: resolved after the PR B report (Jack): it goes first, as in mock #triggers, not last as the panel text order had it. The mock's "No settings needed." and "Change it in Settings" link are kept: the plan doesn't rule them out.
- B1, the panel hides the red notice for `cart_tracking_off`: the notes already warn, with the link (review).
- B1, `useSaveSettings` also drops cached workflows when tracking changes: `useAutosave` keeps the issues of the copy it first gets, so a stale `cart_tracking_off` would stay in the editor after going back from Settings.
- B1, `needsCartTracking` reads the trigger's catalog category `cart`, and is false while WooCommerce is off (review; same rule as `needsShippingSetup`).
- B1, the banner's and overview's Open Settings replace Show step / Fix in editor when tracking is the only issue.
- B2, `templates/core/cartFlow.ts` holds the helpers both folders use, instead of a local `graph()` in each.
- After PR B report (Jack): `CartTrigger::stop_reason()` also stops on an unsubscribed email (after ordered / gone), and `UnsubscribePage::unsubscribe()` cancels that email's waiting cart runs at once, so the coupon templates make no coupon nobody gets.
- B3, the `ViewKeeper` gap: its one-frame check ran before the ResizeObserver measures a new step, so it returned without moving. It now retries for up to 30 frames. Found by reading the code, not in a browser: browser step 9 confirms it. Jack's browser check then found the retry raced the mount fit on a new workflow: `ViewKeeper` now ignores the panel already open at mount.

## Meta
- Target branches: PR A `jack/feature/m8a-abandoned-carts` (from develop cfb9337); PR B `jack/feature/m8b-cart-ui` (from develop e3df6e8; PR A merged as #32, not stacked).
- Base branch: develop
- Two PRs; Jack pushes and opens them by hand (merge commit) after a zip review.
