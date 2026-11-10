# Plan: 026 — M7 Cart tracking + Settings + Health check

## Goal
Carts of logged-in customers and guests are tracked in `yaymatik_carts` (email captured at classic and block checkout), a signed restore link refills a cart and opens checkout, old runs and carts are cleaned up daily, personal data can be exported and erased, settings are stored and editable, and a health check finds stuck runs and late jobs with a fix for each.

## Rules for /wdev (copied from Jack's brief)
- Stop after each CP and mark it ✅ with its hash in this file ("✅ **CP2 …** (a1b2c3d)").
- Commit only when Jack says "commit" or "go". Show the staged list first. Stage by path (never `git add -A`). Never stage `.claude/settings*.json`. No Co-Authored-By / Claude-Session lines.
- Never `git stash`, push or `gh pr create`.
- Risk 2–3 CPs: run `/code-review high` and report findings; skip findings about earlier dev data (reset, not migrated).
- Docs grep: give an allowed-hits list; never reword decision lines to pass it.
- List unused code outside the task at the end of each report.
- Before finishing a CP: search every new function, class, constant, option and export; delete any with no caller (a test alone is not a caller).
- After adding a PHP class: `composer dump-autoload` (classmap-authoritative).

## Facts this plan relies on (WooCommerce 11.2.1, verified by Jack; nothing else assumed)
- Session key = user id; guests `t_` + 30 chars; table `woocommerce_sessions`; guests 2 days, logged-in 7; `WC()->session->get_customer_id()`.
- Login: `woocommerce_guest_session_to_user_id( $guest_key, $user_key )`.
- `woocommerce_cart_updated` fires after every `calculate_totals` (plain cart views too) → compare a hash.
- Classic: a `billing_email` change never reaches the server → own JS. Block: `woocommerce_store_api_cart_update_customer_from_request( $customer, $request )`.
- Order from checkout: classic `woocommerce_checkout_order_created`, block `woocommerce_store_api_checkout_order_processed` (after `update_status( 'pending' )`). WC cancels unpaid checkout orders after `woocommerce_hold_stock_minutes` (default 60).
- `wc_setcookie` sets no SameSite. `_woocommerce_persistent_cart_{blog}` usermeta exists; not used by us.

## Code facts found in research
- `Schema::DB_VERSION` = '4'; install check lists the tables by name (`Schema.php:40`) → add `CARTS`.
- `UnsubscribeLink::ARG_TOKEN` is already `yaymatik_token`. Restore uses the same arg name; `UnsubscribePage` only acts when `yaymatik_unsub` is present, the restore handler only when `yaymatik_cart` is present. No clash.
- `UnsubscribeLink::create_secret()` holds the "own secret option, add_option race" logic. Restore needs the same → shared helper (D8 below), not a copy.
- `RestAPI.php` is 563 lines. New routes go in their own files (`SettingsRoutes`, `HealthRoutes`), which also keeps CP6 separable.
- `ActionSchedulerQueue::enqueue()` / `recover()` already skip when a job exists → the health fixes call them.
- `SendEmail` stores `[ 'to' => … ]` as its run_step output → "sent to" for the exporter.
- `RunStore::FINISHED` = completed, failed, cancelled, skipped → cleanup statuses.
- `reset-data.sh` drops the tables and unschedules `yaymatik_run` / `yaymatik_recover_run` → extend with carts table, `yaymatik_settings`, cart secret and `yaymatik_cleanup`, so it stays a full reset.
- Settings page today: `apps/admin-page/src/routes/settings/SettingsPage.tsx` (empty card). `react-hook-form`, `zod`, TanStack Query are already deps. Table prefix on Jack's site: `wp_`.

## Approach

### Where things live
- `includes/Workflow/Core/Storage/` — `Settings` (option), `CartStore` (table).
- `includes/Workflow/Core/Cart/` (new folder, Workflow Core: no WP page glue) — `CartSnapshot`, `CartTracker`, `EmailCapture`, `RequestOrigin`, `CaptureLimits`, `RestoreLink`, `CartMerge`.
- `includes/Workflow/Core/Runtime/` — `Cleanup`, `HealthCheck`.
- `includes/Engine/API/` — `SettingsRoutes`, `HealthRoutes` (singletons, booted in `YayMatik.php`).
- `includes/Engine/FEPages/RestoreCart.php` — the restore link's request handler.
- `includes/Engine/Privacy/PersonalData.php` — exporter, eraser, policy text.
- `includes/Utils/Secret.php` — `Secret::option( string $name ): string` (moved body of `UnsubscribeLink::create_secret()`; both links call it).
- `assets/js/capture-email.js` — plain JS, no build.

### D1 Checkout abandoned (what M7 stores for it)
`checkout_at` is set (only when empty) when: the email is captured at checkout (classic JS or block hook); an order is created from the cart (pending / failed); a logged-in customer with items opens the checkout page. A pending order never counts on its own. M8 builds both triggers.

### D2 Table and writes
- `Schema::CARTS = 'carts'`, `DB_VERSION` '5'; install check adds it.
- Columns: `id` bigint AI, `session_key varchar(100)` (UNIQUE), `user_id bigint DEFAULT 0`, `email varchar(190) DEFAULT ''` (lowercased via `UnsubscribeLink::normalize()`), `first_name varchar(190) DEFAULT ''`, `items longtext`, `total decimal(19,4)`, `currency char(3)`, `status varchar(20)` (active|ordered), `checkout_at datetime NULL`, `order_id bigint DEFAULT 0`, `restored_at datetime NULL`, `last_activity_at`, `created_at`, `updated_at` (datetime, GMT).
- Keys: `UNIQUE session_key`, `KEY email`, `KEY status (status,last_activity_at)`, `KEY order_id`.
- `items` JSON list, per line: product_id, variation_id, quantity, variation (attributes), name, price (per `woocommerce_tax_display_cart`, as shown), line_total.
- **Hash** = md5 of the lines' (product_id, variation_id, quantity, variation) sorted, + currency. Prices and totals are not in it (a tax / price refresh is not a cart change); they are refreshed on every write. Last hash in the WC session (`yaymatik_cart_hash`).
- **One write per request, at `shutdown` priority 10** (before WC's session save at 20, so the new hash is saved with the session). Only front-end requests (not cron, not wp-cli, and `! is_admin() || wp_doing_ajax()`; Store API and wc-ajax count) with a WC session (`WC()->session->has_session()`) and a non-empty cart; the "emptied" delete below is the one write with an empty cart. Never during a restore request.
- **Exception:** the order link (D7) writes at once in the order hook, not at shutdown. Why: COD / BACS / free orders change status to processing / on-hold in the same request, before shutdown; the status hook must find the row's `order_id`.

### D3 Last activity
- Cart change (hash differs), email capture, order created → write, `last_activity_at` = now.
- Cart or checkout page view (`template_redirect`, `is_cart()` / `is_checkout()`, not order-received) → updates **this session's existing row only** when `last_activity_at` is older than 5 min (or to set a logged-in customer's `checkout_at`). A view never creates a row and never binds a restored cart (D8). Other pages do nothing.

### D4 Email capture
- **Logged-in:** every write sets email = account email, first_name = `billing_first_name` user meta, else the user's `first_name`.
- **Guests:** only what they type at checkout:
  - Classic: `assets/js/capture-email.js`, enqueued only on classic checkout (`is_checkout()`, not an endpoint, page has no `woocommerce/checkout` block), guests only, both settings on. On `change` / `blur` of `#billing_email` it posts `email` + `#billing_first_name` to `WC_AJAX::get_endpoint( 'yaymatik_capture_email' )` (`fetch`, same-origin credentials); sends each distinct value once.
  - Block: `woocommerce_store_api_cart_update_customer_from_request` → `$customer->get_billing_email()` / `get_billing_first_name()`; acts only when the pair differs from what this session last captured (session var `yaymatik_captured`), so repeated address updates don't eat the limits.
  - Also: the order's billing email / first name when an order is created from the cart (fills empty fields only).
- Any email is captured, registered or not. Stored only on the current session's row. Never look up or link a user by email; `user_id` only from the logged-in user; never prefill from an account. A guest session never merges, overwrites, empties or marks a user's cart (only the D7 paid-order rule marks carts by email).
- **Classic endpoint** `wc_ajax_yaymatik_capture_email`: always `wp_send_json_success()` (200 `{"success":true}`) — new, registered, invalid, rate-limited, refused, capture off. Logged (reason only, no email): "not from the store" and "rate limit", each at most once an hour (transient flag); "no cart", "logged in", "not an email" are not logged.
- **CSRF** (classic only; the Store API checks its own nonce): `RequestOrigin::allowed( array $headers, string $home_url ): bool` — `Sec-Fetch-Site` present → only `same-origin`; else `Origin` host **and port** must equal `home_url()`'s; else `Referer`'s; none of the three → refuse. Default ports normalized (no port = 80 for http, 443 for https).
- Requires an existing WC session and a non-empty cart.
- **Limits** (`CaptureLimits`, transients, both paths): 10 / 10 min per session, 20 / 15 min per IP (`WC_Geolocation::get_ip_address()`, HMAC with the cart secret; it reads proxy headers, so a spoofed IP only skips the per-IP limit — the session and site-wide limits still hold), 500 / 15 min site-wide. One email from at most 3 sessions per 24 h: `CartStore::sessions_with_email( $email, $since )` counts other rows with that email and `updated_at` in the last 24 h; ≥ 3 → dropped silently.
- Guests only when `cart_tracking` and `capture_guest_email` are both on.

### D5 Privacy text, no consent box
- `wp_add_privacy_policy_content( 'YayMatik', … )` on `admin_init`: carts of logged-in customers and guests, guest emails typed at checkout saved before the order is placed, used for cart reminder emails, deleted after the retention days.
- The tracking toggle's help text (PR B) says it includes saving guest emails typed at checkout before the order is placed.
- ROADMAP-dev v1.1: backlog line "Opt-in mode (consent checkbox) + transactional-only workflows".

### D6 Defaults
cart_tracking false · abandon_minutes 60 (15–1440) · capture_guest_email true (only with tracking on) · cart_retention_days 60 (7–365) · run_retention_days 90 (7–730) · unsubscribe_text ''. Tracking off = no tracking front-end hooks (capture, tracking, rekey); data kept until retention. The restore handler stays on (links in emails already sent still work): it merges and redirects, with tracking suppressed and no binding.

### D7 Lifecycle
- **Login** (`woocommerce_guest_session_to_user_id`): `CartStore::rekey( $guest_key, $user_id )` — delete the user's row (`session_key` = user id), then the guest row gets `session_key` = user id, `user_id`, and email / first_name from the account (D4 logged-in rule). No guest row → nothing changes.
- **Emptied** with no order (`order_id` 0 and none created this request) → delete the row.
- **Logout never deletes, empties or marks a row.** WC's `forget_session()` calls `wc_empty_cart()` while the session key is still the user id. Every place the "emptied" rule runs returns early when `did_action( 'wp_logout' )`.
- **Order created** (both hooks): bind a restored cart first (D8), then the session's row (inserted from the cart if missing) gets `order_id` (overwrites an older one), `checkout_at` if empty, email / first_name if empty. Immediate write (D2).
- **Paid** (`woocommerce_order_status_changed` to processing, completed or on-hold; hooked even while tracking is off, so later M8 data is right): row with that `order_id` → `ordered`. If `$order->get_total( 'edit' ) > 0`: every `active` cart (M8 adds `abandoned`) with the lowercased billing email and `last_activity_at` ≤ the order's `date_created` (GMT) → `ordered`.
- **New cycle:** an `ordered` row whose session cart changes (hash) → status active, `created_at` now, `checkout_at` / `order_id` / `restored_at` cleared, new items. Its old restore links stop working (token signs `created_at`).

### D8 Restore link
- `RestoreLink::url( int $id, string $created_at ): string` → `home_url('/')` + `yaymatik_cart=<id>&yaymatik_token=<base64url HMAC-SHA256(id|created_at)>`; secret `Secret::option( 'yaymatik_cart_secret' )`; `verify()` with `hash_equals`. Valid while the row exists.
- `RestoreCart` on `wp_loaded` priority 20 (after WC loads the cart from the session), only when `yaymatik_cart` is in the query; `nocache_headers()`, `Referrer-Policy: no-referrer`. Starts a WC session cookie when there is none (so notices and the cart survive the redirect).
- `CartMerge::plan( saved lines, current lines )`: missing lines added, same product + variation → the larger quantity. No coupons. Deleted, out of stock, not purchasable, or refused by `add_to_cart` → skipped (WC's own error notices from a refused add are removed; only ours show).
- Outcomes, checked in this order:
  1. Row gone / bad token → shop + "This cart is no longer available."
  2. Row ordered → shop + "You already placed an order for these items."
  3. Every saved line unavailable → shop + "This cart is no longer available."
  4. Every available line already in the current cart (nothing to add or raise) → checkout, no notice ("Some items are no longer available." when any were skipped).
  5. Else checkout + "We added your saved items to your cart." (+ "Some items are no longer available." when any were skipped).
- Prefill `WC()->customer` billing email / first name only when empty **and** the row's `user_id` is 0 (a user's row holds an account email: never prefill it).
- Outcomes 4–5: session `yaymatik_restored_cart` = row id (only while tracking is on); `wp_safe_redirect( wc_get_checkout_url(), 302 )`; exit.
- The GET writes no carts row: tracking is suppressed, and the merged cart's hash is stored in the WC session so the next page view isn't seen as a cart change. Mail scanners change nothing in our table.
- **Binding** (tracking on only): the session's first real activity (cart change, email capture, order created; views don't count) with `yaymatik_restored_cart` set: if that row still exists, is not `ordered`, and its `user_id` is 0 or the current user → delete this session's own row (if another), then the original row gets this `session_key` (and `user_id` if logged in) and `restored_at` = now; the write then goes on that row. In every case the session var is cleared.
- `RestoreLink::url()` caller in M7 = `check-carts`; M8.5 email block later.

### D9 Settings
- `Settings` (option `yaymatik_settings`, autoload): `defaults()`, `all()` (stored merged over defaults, each key sanitized: bools, ints clamped to range, `unsubscribe_text` plain text: `wp_check_invalid_utf8`, `wp_strip_all_tags` (line breaks kept), `\r\n` → `\n`, trim, capped at 1000 chars with `mb_substr`; no `sanitize_textarea_field`, which turns a lone `<` into `&lt;` and drops `%xx`; unknown keys dropped), `get( string $key )`, `update( array $changes ): array|WP_Error` (out of range / wrong type → `WP_Error` with `data.errors[{ field, message }]`, nothing saved; text over 1000 chars is capped, not refused).
- REST `GET /settings`, `PUT /settings` (partial body, camelCase keys), `manage_options`. Response: the six keys in camelCase + read-only `unsubscribeDefault` (the default "unsubscribed" body, for the field's placeholder).
- `UnsubscribePage` STATE_DONE body = `unsubscribe_text` when not empty, printed with `esc_html` + `nl2br`.
- `abandon_minutes`: in M7 read only by REST and the Settings page; M8's sweep reads it (D9 lists it; kept on purpose).

### D10 Retention
- `Cleanup::HOOK = 'yaymatik_cleanup'`, group `yaymatik`, daily recurring (`as_schedule_recurring_action`). `Cleanup::ensure()` on `action_scheduler_init`: reads the autoloaded option `yaymatik_cleanup_checked_at` (Unix time); if under 1 h old, nothing; else schedules the job when `as_has_scheduled_action` says none, and rewrites the option (at most hourly). An option, not a transient: a non-autoloaded transient (no object cache) costs a query on every request.
- Runs: finished (`RunStore::FINISHED`) with `finished_at` older than `run_retention_days`, deleted workflows included; their `run_steps` first. Never queued / running / waiting, and never a run whose chain (`root_run_id`) still has one (the loop guard walks `parent_run_id` and stops at a missing row).
- Carts: `last_activity_at` older than `cart_retention_days`.
- Batches of 500 ids; stops after ~20 s; what's left goes next day.

### D11 Privacy
- **Runs about the person** = the five text rules below OR `runs.subject` IN the person's subjects: `order:<id>` for `wc_get_orders( [ 'customer' => $email, 'return' => 'ids', 'limit' => -1, 'type' => 'shop_order' ] )`, `cart:<id>` for the email's carts. A run started by a trigger stores refs (`subject` `order:2406`, scope `trigger` refs), never the email, so the text rules alone miss it.
- Exporter (paged, 50 per page): carts (items, dates, email, first name), unsubscribe record, runs about the person, any status (workflow name or "Deleted workflow", started, status, sent to).
- Eraser: cancel the waiting runs about the person; anonymize runs in `runs.scope`, `runs.error`, `run_steps.input`, `.output`, `.error`; delete the email's carts on the last page. Rows, statuses, dates kept.
- **Matching rule** (a matched row is always changed; no other address is touched). The email is normalized (`UnsubscribeLink::normalize()`) first.
  - JSON columns (`runs.scope`, `run_steps.input`, `run_steps.output`): needle = `wp_json_encode( $email )` (quoted, escaped as the data was stored). Find: `LIKE '%' . $wpdb->esc_like( $needle ) . '%'` (case-insensitive: the columns use the table's `_ci` collation). Replace: `str_ireplace( $needle, '"[deleted]"', … )`. Only whole JSON string values equal to the email match; an address inside a longer string (e.g. a literal one in an email body) is left as is.
  - Text columns (`runs.error`, `run_steps.error`): find in SQL with `REGEXP` using the same boundaries as PHP — `(^|[^A-Za-z0-9._%+-])<email>($|[^A-Za-z0-9.-])`, the email escaped for regex by a small helper (ERE metacharacters only: `.[]()*+?{}|^$\`), passed through `prepare()`. Its case-insensitivity relies on the tables' `_ci` collation (set by `$wpdb->get_charset_collate()` in `Schema::maybe_install()`); the check-carts mixed-case test (`Bob@X.test`) guards it. Replace: `preg_replace( '/(?<![A-Za-z0-9._%+-])' . preg_quote( $email, '/' ) . '(?![A-Za-z0-9.-])/i', '[deleted]', … )`. Why not `LIKE` here: `LIKE '%bob@x.test%'` also finds `jimbob@x.test`, which the replace leaves alone, so a page could find rows it never changes.
- **Paging:**
  - Exporter: runs about the person, `ORDER BY id LIMIT 50 OFFSET ( $page - 1 ) * 50`; stable, as exporting changes nothing; done when a page returns fewer than 50.
  - Eraser: stateless; the subjects are read on every page (orders from WooCommerce, carts from the table, which keeps them until the last page). Each page re-queries the first 50 runs that match a text rule, or match a subject and are waiting; queued and running runs left out (a worker holds their scope in memory and would write the email back). Waiting ones are cancelled first (`RunStore::cancel_waiting` + `RunStepStore::cancel_waiting`, reason "The customer's personal data was erased."), then all are anonymized; done when fewer than 50 matched. Cancelled runs no longer match the subject branch, and finished subject-only runs are export-only, so paging ends. A page that cancels or changes no row → done = true, `Logger::error` and a retained message (never loops). On the last page: a queued or running run about the person → `items_retained` + "A workflow run is in progress; erase again in a few minutes." and the carts are kept (so erasing again still finds runs about them); else the carts are deleted.
  - Text boundary after the email: a dot ends the address unless a letter or digit follows (`bob@x.test.` ending a sentence matches, `bob@x.test.au` doesn't): SQL `($|[^A-Za-z0-9.-]|[.]($|[^A-Za-z0-9]))`, PHP `(?![A-Za-z0-9-]|\.[A-Za-z0-9])`.
- Unsubscribe record kept: `items_retained` true + message "The unsubscribe record is kept so this address gets no more workflow emails." Generated coupons out of scope.

### D12 Health check v1
- `HealthCheck::run(): array` — four checks, max 50 runs each:
  - `runs_queued` (a): queued with `started_at` > 5 min ago, or waiting with `wait_until` > 15 min ago, and no pending / in-progress `yaymatik_run` job for it → fix `enqueue` (`ActionSchedulerQueue::enqueue( $id )`).
  - `runs_running` (b): running with no `yaymatik_run` and no `yaymatik_recover_run` job → fix `recover` (`ActionSchedulerQueue::recover( $id )`).
  - `jobs_late` (c): pending `yaymatik` group jobs past due > 15 min → info only, server cron guide link `https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/`.
  - `cleanup_job` (d): `yaymatik_cleanup` not scheduled, or past due > 1 h → fix `schedule` (unschedule + schedule again).
- `GET /health/checks` → `{ checks: [ { id, status: ok|problem|info, count, runIds, fix: enqueue|recover|schedule|null } ], checkedAt }`. `POST /health/fix { check }` → runs that check again on the server (never trusts ids from the client), fixes what it finds, returns `{ fixed, checks }`. `/health` ping stays. No auto-fix except D10's `ensure()`.
- Pure part for PHPUnit: `HealthCheck::classify( array $runs, callable $has_job, int $now )`.

### D13 Docs (same commit as the code)
- New decisions entry **"M7. Cart tracking"** in `docs/workflow-core-decisions.md` (after P3–P6), with a contents row (`[M7](#m7-cart-tracking)` | Cart tracking | Owner) and a design §11 row; the row holds only what has landed (CP2: `M7 | Carts in their own table; no own tracking cookie | §2`), and CP3–CP5 each add their part (capture any email, same response; restore GET writes nothing; erase anonymizes runs). One line of why each, each line written in the CP that builds it:
  - CP2 (creates the entry, contents row, §11 row): carts, not pending orders — WC cancels unpaid orders after the hold-stock minutes. No own tracking cookie — WC's session is enough; cost: a guest is unknown once the WC session ends.
  - CP3: capture any email with the same response — no account enumeration, and guest checkouts of registered customers still count.
  - CP4: the restore GET writes nothing — mail scanners open links.
  - CP5: erase anonymizes runs and keeps the unsubscribe record — run history stays countable, and the address keeps getting no emails.
- CP2: ROADMAP-v1.0 Cart abandoned "an email known from an earlier visit" → "an email known from an earlier visit in the same browser (WooCommerce session)"; Checkout abandoned: + "A pending or failed order counts as reaching checkout; a pending order alone never starts it." Design §2 carts row. Decisions C11 note: carts, not pending orders, because WC cancels unpaid orders after the hold-stock minutes.
- CP5: design §3 cleanup job + schedule ensure (`yaymatik_cleanup_checked_at`); ROADMAP-dev v1.1 opt-in backlog line.
- CP6: design §9 the four checks (rows a–d replace the two stuck-run rows); ROADMAP-dev M7.5 list.
- CP7: ROADMAP-dev M7 status + M8 follow-ups; `.claude/docs/context-php-backend.md` Key Paths (Cart/, Settings, CartStore, Cleanup, HealthCheck, RestoreCart, PersonalData, new routes, `check-carts`).

## Checkpoints

### PR A — `jack/feature/m7a-carts-settings` from develop 6425140

0. ✅ **CP0 Docs: F2 done** (c2a2094) — Risk: 1
   - Files: `docs/ROADMAP-dev.md` — phase table F2 "✅ Done"; F2 Status "✅ Done. Merged into `develop` (PR #29, `6425140`)."; F2.1–F2.4 ✅.
   - Test: read; grep `merge pending` in ROADMAP-dev → no F2 hit.
   - Browser: none.

1. ✅ **CP1 Settings store + REST** (5b9442e) — Risk: 2
   - Files: new `Core/Storage/Settings.php`, `Engine/API/SettingsRoutes.php`; `YayMatik.php` (boot), `Engine/FEPages/UnsubscribePage.php` (done text), `tests/Unit/Core/Storage/SettingsTest.php`, `tests/stubs.php` (option / sanitize stubs if missing).
   - Symbols → callers: `Settings::defaults/all/get/update` → `SettingsRoutes`, `UnsubscribePage` (later CartTracker, EmailCapture, Cleanup); `Settings::OPTION` → `Settings`, `reset-data.sh` (CP2); `SettingsRoutes::register_routes/get_settings/update_settings` → `rest_api_init`.
   - Tests: PHPUnit defaults, clamps / errors per key, bool cast, unknown keys dropped, text sanitized and capped.
   - Browser (console on any wp-admin page):
     1. `await (await fetch('/wp-json/yaymatik/v1/settings',{headers:{'X-WP-Nonce':yayMatikMeta.wp.restNonce}})).json()` → defaults + `unsubscribeDefault`.
     2. PUT `{abandonMinutes:30, unsubscribeText:'Bye'}` → returns new values; repeat GET → kept.
     3. PUT `{abandonMinutes:5}` → 400 with `field: 'abandonMinutes'`; GET → still 30.
     4. Sequel Ace: `SELECT option_value FROM wp_options WHERE option_name='yaymatik_settings';`
     5. Open an unsubscribe link, click Unsubscribe → body "Bye". PUT `unsubscribeText:''` → default text again.
     6. As a shop-manager-only user → GET 403.

2. ✅ **CP2 Carts table + tracking + lifecycle** (f5e1243) — Risk: 3
   - Files: `Core/Storage/Schema.php` (v5, CARTS), new `Core/Storage/CartStore.php`, `Core/Cart/CartSnapshot.php`, `Core/Cart/CartTracker.php`; `YayMatik.php` (boot inside the WooCommerce check); `dev/scripts/reset-data.sh` (drop carts, delete `yaymatik_settings`); new `dev/scripts/check-carts.sh` (line 2 = listing text) + `check-carts.php`; docs per D13 CP2; `tests/Unit/Core/Cart/CartSnapshotTest.php`.
   - `./run.sh check-carts` (wp-cli; temp rows under fake session keys `t_check…`, temp orders and products; removed at shutdown; PASS / FAIL): row write + hash skip (same lines write nothing; a price change writes nothing); emptied → deleted; logout empty (`do_action( 'wp_logout' )` then empty) → row kept; rekey (user's older row dropped, guest row moved, account email); ordered by `order_id`; ordered by the email rule (total > 0, `last_activity_at` ≤ `date_created`; later activity and a 0-total order untouched); reset cycle (fields cleared, `created_at` new).
   - Symbols → callers:
     - `CartStore::find_by_session/upsert/touch/delete_by_session/rekey/link_order/mark_ordered_by_order/mark_ordered_by_email/reset_cycle` → `CartTracker`.
     - `CartSnapshot::from_wc(): ?self`, `hash()`, `lines()` → `CartTracker`; `CartSnapshot::from_lines()` → `CartTracker` (via `from_wc`) and `check-carts`.
     - `CartTracker::emptied()` (with the `wp_logout` guard) → `shutdown`, `check-carts`.
     - `CartTracker::boot()` → `YayMatik.php`; hook methods `cart_updated`, `view`, `shutdown`, `login`, `order_created`, `order_status_changed` → WC hooks; `record( CartSnapshot, string $reason )` → `shutdown`, `check-carts`; `suppress()` → `RestoreCart` (CP4).
   - Tests: PHPUnit hash stable across line / attribute order, changes with qty / variation / currency, not with price.
   - Browser (tracking turned on with CP1's PUT; private window; Sequel Ace query Q = `SELECT id,session_key,user_id,email,first_name,status,checkout_at,order_id,restored_at,last_activity_at,created_at,updated_at,JSON_LENGTH(items) n,total FROM wp_yaymatik_carts ORDER BY id DESC;`):
     1. Load the site once → `wp_options.yaymatik_db_version` = 5; table exists.
     2. Guest: open shop, no add → Q: no row.
     3. Add a product → Q: one row, `session_key` `t_…`, n=1, email ''.
     4. Reload the cart page 3 times → Q: `updated_at` unchanged (hash skip, view < 5 min).
     5. Change quantity → Q: `updated_at` moves, n=1, new total.
     6. Empty the cart → Q: row deleted.
     7. Add again, then log in as a customer (with an older own cart row from another browser) → Q: one row, `session_key` = user id, `user_id` set, account email; the older row gone.
     7a. Logged-in customer with a cart logs out → Q: the user's row is still there, same items, status unchanged. Log back in → same row.
     8. Logged in, open checkout → Q: `checkout_at` set.
     9. Place a BACS order (on-hold) → Q: `order_id` set, status `ordered` in the same request.
     10. Add an item again → Q: same row, status active, `created_at` new, `checkout_at` / `order_id` empty.
     11. Pending: add item, place a "Check payments" order and leave it pending (or a failing gateway) → Q: `order_id`, `checkout_at`; status active. Mark it processing in wp-admin → Q: `ordered`.
     12. Email rule: guest cart with email X (set by SQL for now; CP3 captures it), then a paid admin order for X with total > 0 → Q: guest row `ordered`. Same with a 0-total order → unchanged.
     13. Tracking off (PUT) → add to cart in a new private window → Q: no new row.
     14. `./run.sh check-carts` → all PASS.

3. ✅ **CP3 Email capture (classic + block) + CSRF + limits** (5225439) — Risk: 3
   - Files: new `Core/Cart/EmailCapture.php`, `Core/Cart/RequestOrigin.php`, `Core/Cart/CaptureLimits.php`, `assets/js/capture-email.js`; `CartTracker.php` (capture as an activity); `CartStore.php` (`sessions_with_email`); `Utils/Secret.php` + `UnsubscribeLink::create_secret()` → calls it (IP hash needs the cart secret); `check-carts.php` (capture checks); decisions M7 line (CP3); `tests/Unit/Core/Cart/RequestOriginTest.php`, `CaptureLimitsTest.php`.
   - `check-carts` adds: 3-session cap (fourth session's capture dropped); the classic endpoint's body is identical for a new, a registered, an invalid email, a refused origin and capture off (`wp_send_json_success` captured via `wp_die_ajax_handler` filter).
   - Symbols → callers: `EmailCapture::boot()` → `CartTracker::boot()`; `ajax()` → `wc_ajax_yaymatik_capture_email`; `store_api()` → the Store API hook; `enqueue()` → `wp_enqueue_scripts`; `RequestOrigin::allowed()` → `EmailCapture::ajax`; `CaptureLimits::allow( $session, $ip )` → `EmailCapture`; `CartStore::sessions_with_email()` → `EmailCapture`; `CartTracker::capture( $email, $first_name )` → `EmailCapture`; `Secret::option()` → `UnsubscribeLink`, `CaptureLimits`, `RestoreLink` (CP4).
   - Tests: PHPUnit origin rules (same-origin ok; cross-site / same-site refused; Origin match, mismatch, `www.` differs; `http://localhost:8080` vs home `http://localhost:8080` → match; `:8081` vs `:8080` → refused; `https://shop.test:443` vs `https://shop.test` → match; Referer fallback; none → refuse); limits (11th per session refused, IP and site counters, window reset). `UnsubscribeLinkTest` still green.
   - Browser (tracking + capture on; Q from CP2):
     1. Classic checkout page (shortcode), guest, private window, item in cart. DevTools Network: type email A, tab out → `?wc-ajax=yaymatik_capture_email` 200 `{"success":true}`. Q: email A, first_name, `checkout_at` set.
     2. Change to a registered customer's email B → same body. Q: email B on the guest row; the customer's own row untouched; `user_id` 0.
     3. Type `not-an-email` → same body. Q: unchanged. Log (WC → Status → Logs, `yaymatik`) has the refusal, no email.
     4. Console: `fetch('/?wc-ajax=yaymatik_capture_email',{method:'POST',body:new URLSearchParams({email:'c@x.test'})})` from another site's page (or curl with `-H 'Sec-Fetch-Site: cross-site'`) → same body; Q unchanged; log says refused.
     5. Block checkout page, new private window, item in cart: type email C, leave the field → Network `batch` request with `cart/update-customer` holding `billing_address.email`. Q: email C, `checkout_at`.
     6. Edit the address (city) again → Q: `updated_at` unchanged (same pair, no capture).
     7. Four private windows capture email D → Q: only three rows have D.
     8. Turn capture off (PUT) → classic: JS not loaded (Network has no `capture-email.js`); block: email E typed → Q unchanged.
     9. Place the block order as guest with email F → Q: `order_id`, email stays C (filled only when empty).
     10. `./run.sh check-carts` → all PASS.

4. ✅ **CP4 Restore link + handler + notices + binding** (1ce60fb) — Risk: 3
   - Files: new `Core/Cart/RestoreLink.php`, `Core/Cart/CartMerge.php`, `Engine/FEPages/RestoreCart.php`; `CartTracker.php` (binding, suppress, hash after restore); `YayMatik.php` or `CartTracker::boot()` (handler boot); `reset-data.sh` (delete `yaymatik_cart_secret`); `check-carts.php` (restore checks); decisions M7 line (CP4); `tests/Unit/Core/Cart/RestoreLinkTest.php`, `CartMergeTest.php`.
   - `check-carts` adds: restore URL verifies, a changed token / other id / new cycle's `created_at` don't; binding (row unchanged by the restore, bound on the first `record()`, own row deleted; a user's row never bound to a guest; no binding with tracking off); the five outcomes in order (gone, ordered, all unavailable, all already in cart, added with and without skipped).
   - Symbols → callers: `RestoreLink::url()` → `check-carts` (M7), M8.5 later; `RestoreLink::from_options()`, `verify()` → `RestoreCart`; `RestoreLink::SECRET_OPTION` → `RestoreLink`, `EmailCapture`; `ARG_CART` → `RestoreLink`, `RestoreCart`; `CartMerge::plan()`, `CartSnapshot::line_key()` → `RestoreCart`, `CartMerge`; `CartStore::find()` → `RestoreCart`, `CartTracker`; `CartStore::bind()` → `CartTracker`; `CartTracker::suppress()`, `restored()` → `RestoreCart`; `RestoreCart::maybe_handle()` → `wp_loaded`; `CartTracker::bind_restored()` → `CartTracker::record` (reached from `shutdown`, `capture`, `order_created`).
   - Tests: PHPUnit url args; verify ok / bad token / other id / changed `created_at`; merge: missing added, larger qty wins, equal qty no change, unavailable counted as skipped, every available line already in the cart → nothing to do (outcome 4), all unavailable → outcome 3.
   - Browser (window 1 = private, the cart; window 2 = a second private window; tracking + capture on; URL printed by `wp eval 'echo YayMatik\Workflow\Core\Cart\RestoreLink::from_options()->url( ID, "CREATED_AT" );'` with the row's values; Q from CP2):
     1. Window 1: guest cart with 2 products, email captured. Q: note id, `session_key`.
     2. Window 2 (empty cart): open the URL → checkout with both items, notice "We added your saved items to your cart.", email / first name prefilled. Q: row unchanged (`session_key`, `updated_at`, `restored_at` empty); no new row.
     3. Window 2: reload checkout, open cart → Q still unchanged.
     4. Window 2: add a product → Q: original row now has window 2's `session_key`, `restored_at` set, n=3; no second row.
     4a. Window 1 (still holds the same cart): open the URL → checkout, **no notice**. Change its cart → Q: a new row for window 1, email empty.
     5. Third window with its own item in the cart, one saved product set to out of stock → "We added your saved items to your cart." + "Some items are no longer available.", the others added; email **not** prefilled (cart wasn't empty).
     6. Trash both products → open the URL → shop + "This cart is no longer available."
     7. Mark the row ordered (place a paid order from the bound window) → open the URL → shop + "You already placed an order for these items."
     8. Delete the row in Sequel Ace → URL → shop + "This cart is no longer available." Change one char of the token → same.
     9. Restore a logged-in customer's cart URL in a guest window → items added, email **not** prefilled; add-to-cart → the user's row keeps its `session_key`; the guest gets its own row. Logged in as another customer, open a guest cart's URL → items added, the checkout keeps the account email.
     10. Response headers of the URL (Network): `Cache-Control: no-cache…`, `Referrer-Policy: no-referrer`, 302 to checkout; a fresh private window gets a `wp_woocommerce_session_…` cookie on that 302.
     10a. Open `/wp-json/wc/store/v1/cart?yaymatik_cart=ID&yaymatik_token=…` → normal JSON, no redirect.
     11. Tracking off (PUT) → open a valid URL in a new private window → checkout with the items; add a product → Q: original row unchanged, no new row.
     11a. Classic theme (Storefront) with the Checkout block on the checkout page: open a valid URL in a new private window → does the "added" notice show? Same on classic checkout (shortcode) and a block theme.
     12. `./run.sh check-carts` → all PASS.

5. ✅ **CP5 Cleanup job + schedule ensure + exporter / eraser + privacy text** (60f8b5d) — Risk: 3
   - Files: new `Core/Runtime/Cleanup.php`, `Engine/Privacy/PersonalData.php`; `RunStore.php` (`finished_before`, `delete_with_steps`, `ids_mentioning`, `anonymize`), `RunStepStore.php` if the step side needs its own query; `CartStore.php` (`delete_inactive_before`, `for_email`, `delete_for_email`); `UnsubscribeStore.php` (`find` for the export date); `YayMatik.php` (boot); `reset-data.sh` (unschedule `yaymatik_cleanup`, delete `yaymatik_cleanup_checked_at`); `check-carts.php` (cleanup + eraser checks); docs per D13 CP5 (+ decisions M7 line).
   - `check-carts` adds: cleanup counts (old finished runs + steps deleted, deleted workflows' runs too, waiting / queued / running kept, old carts deleted, fresh kept); eraser anonymize (`[deleted]` in the five columns; statuses and dates kept; carts gone); erasing `bob@x.test` leaves `jimbob@x.test` and `bob@x.test.au` untouched in all five columns; `esc_like`: erasing `a_b@x.test` leaves `axb@x.test` untouched; a mixed-case stored email (`Bob@X.test`) is erased; a run whose only mention is in an error text is erased; 120 matching runs take three eraser pages (done on the third) and three exporter pages; a forced no-change page ends with done = true and a log line.
   - Symbols → callers: `Cleanup::HOOK` → `Cleanup`, `HealthCheck` (CP6), `reset-data.sh`; `Cleanup::CHECKED_OPTION` → `Cleanup::ensure`, `reset-data.sh`; `Cleanup::ensure()` → `action_scheduler_init`; `Cleanup::run()` → `yaymatik_cleanup`, `check-carts`; `PersonalData::exporters/erasers/export/erase/policy_text` → WP filters and `admin_init`; store methods → `Cleanup` / `PersonalData`.
   - Tests: `check-carts` (above). PHPUnit none (all SQL).
   - Browser:
     1. Tools → Scheduled Actions, search `yaymatik_cleanup` → one pending, daily.
     2. Delete it there, then `wp option delete yaymatik_cleanup_checked_at` → reload any page → it's back; Sequel Ace `SELECT option_value, autoload FROM wp_options WHERE option_name='yaymatik_cleanup_checked_at';` → a timestamp, autoload on. Reload again within the hour → the value doesn't change.
     3. Settings → Privacy → Policy Guide → YayMatik section text.
     4. Tools → Export Personal Data for a guest email with a cart and a sent workflow email → download → groups Carts, Unsubscribe, Workflow runs with the right values.
     5. Tools → Erase Personal Data for it → "removed / retained" message with the unsubscribe note. Q: no carts for it. Sequel Ace `SELECT id,scope,error FROM wp_yaymatik_runs WHERE scope LIKE '%[deleted]%'` and the same on `wp_yaymatik_run_steps` (input / output) → the email replaced; statuses and dates kept.
     6. Run the cleanup now from Scheduled Actions (Run) with `runRetentionDays` 7 and an old finished run backdated by SQL → that run and its steps gone; a backdated `waiting` run kept.
     7. `./run.sh check-carts` → all PASS.

6. ✅ **CP6 Health check REST** (3acde68) — Risk: 2 · separable: own files + one boot line + docs; moves to 1.0.1 (with B2) if PR A isn't merged by Wed Oct 28 evening.
   - Files: new `Core/Runtime/HealthCheck.php`, `Engine/API/HealthRoutes.php`; `RunStore.php` (`overdue`); `Cleanup.php` (`reschedule`, private `schedule` shared with `ensure`); `YayMatik.php` (boot, inside the WooCommerce check: Action Scheduler comes with it); `check-runs.php` (section 14); `tests/Unit/Core/Runtime/HealthCheckTest.php`; docs per D13 CP6.
   - Symbols → callers: `HealthCheck::run()` → `HealthRoutes::checks/fix`; `HealthCheck::fix( string $check )` → `HealthRoutes::fix`; `HealthCheck::classify()` → `HealthCheck::runs`, test; `HealthCheck::FIXES` → `HealthCheck::result`, `HealthRoutes` (enum); `RunStore::overdue()` → `HealthCheck::runs`; `Cleanup::reschedule()` → `HealthCheck::fix`; `HealthRoutes::register_routes/checks/fix` → `rest_api_init`.
   - Changes vs plan: a pending or in-progress `yaymatik_recover_run` job also counts for queued / waiting runs (the Worker's recovery path takes them too; else a fix adds a second job). `POST /health/fix` enum = the three fixable ids, so `jobs_late` and unknown ids both get WP's 400 `rest_invalid_param`. `jobs_late` carries `guide` (the cron guide URL). A fix handles at most the 50 ids of its check per call. The run checks read the 500 oldest candidates (`RunStore::overdue` LIMIT); a full read adds `capped: true` to both run checks and `count` is a lower bound (Jack, review).
   - Tests: PHPUnit classify (7); `check-runs` section 14 (10 lines).
   - Browser (wp-admin YayMatik page, console; `H = {'X-WP-Nonce': yayMatikMeta.wp.restNonce, 'Content-Type': 'application/json'}`; `get = async () => (await (await fetch('/wp-json/yaymatik/v1/health/checks',{headers:H})).json())`; `fix = async c => (await fetch('/wp-json/yaymatik/v1/health/fix',{method:'POST',headers:H,body:JSON.stringify({check:c})})).json()`):
     1. `await get()` → four checks, all `ok` (or `jobs_late` `info` with `guide` if WP-Cron is late); `checkedAt` ISO.
     2. Sequel Ace: pick a finished run id X → `UPDATE wp_yaymatik_runs SET status='queued', finished_at=NULL, started_at=UTC_TIMESTAMP() - INTERVAL 10 MINUTE WHERE id=X;` (it has no job) → `await get()` → `runs_queued` `problem`, `fix` `enqueue`, `runIds` contains X.
     3. `await fix('runs_queued')` → `fixed` ≥ 1, its `checks` show `runs_queued` ok; Tools → Scheduled Actions, search `yaymatik_run` → a pending one with args [X].
     4. Another finished run Y → `UPDATE wp_yaymatik_runs SET status='running', finished_at=NULL WHERE id=Y;` → `await get()` → `runs_running` `problem` [Y] → `await fix('runs_running')` → Scheduled Actions has a pending `yaymatik_recover_run` [Y]; `runs_running` ok.
     5. Waiting: `UPDATE wp_yaymatik_runs SET status='waiting', finished_at=NULL, wait_until=UTC_TIMESTAMP() - INTERVAL 20 MINUTE WHERE id=Z;` → `runs_queued` problem [Z]; with `INTERVAL 10 MINUTE` → ok.
     6. Scheduled Actions: search `yaymatik_cleanup`, Cancel the pending one → `await get()` → `cleanup_job` `problem`, `fix` `schedule` → `await fix('cleanup_job')` → `fixed` 1; Scheduled Actions shows a new pending one about an hour ahead.
     7. `await fix('jobs_late')` and `await fix('nope')` → `code` `rest_invalid_param` (status 400).
     8. `await (await fetch('/wp-json/yaymatik/v1/health',{headers:H})).json()` → `{ ok: true, version }` unchanged.
     9. Let the queued / recovery jobs run (or Run them in Scheduled Actions): X, Y, Z end as WP-Cron decides; reset them by SQL if you want them back as they were.

7. ✅ **CP7 Full run + docs + PR text** (2064e18) — Risk: 1
   - Files: docs per D13 CP7; `.claude/plans/dev/026-pr-a.md`. No new check code: `check-carts` was built up in CP2–CP5.
   - Run all: `./run.sh check-carts`, `check-runs`, `check-orders`, `check-status-flow`, `check-storage`, `check-late-module`; PHPUnit + phpcs.
   - Docs: ROADMAP-dev M7.1–M7.5, M7.8 ⏳ (M7.5 only if CP6 stays); M8 Follow-ups (the six lines from the brief); context-php-backend Key Paths.
   - Docs grep over `docs/` and `.claude/docs/` for `earlier visit`, `pending order`, `persistent_cart`, `consent`, `Today:** an empty page`. Allowed hits only: ROADMAP-v1.0 Cart abandoned line (new wording); ROADMAP-v1.0 Checkout abandoned pending line; decisions C11 note; decisions M7 "carts, not pending orders" line; ROADMAP-dev v1.1 opt-in backlog line ("consent"); ROADMAP-v1.0 Settings "Today: an empty page" (stays until PR B). Decision lines are never reworded to pass.
   - PR text `026-pr-a.md`: after pulling, load a page (DB v5); turn tracking on via REST (UI comes in PR B); browser checks of CP1–CP6; `./run.sh check-carts`, `check-runs`, `check-orders`, `check-status-flow`, `check-storage`, `check-late-module`.
   - Browser: the full CP2–CP4 lists once more on classic and block checkout, light theme default (Storefront / Twenty Twenty-Five).

### PR B — `jack/feature/m7b-settings-ui` from develop cf1e841 (PR A merged as #30)

B1. ✅ **Settings page** (d55b0f0) — Risk: 2
   - Files: `packages/types/src/settings.ts` (+ index export); `routes/settings/api/settings.api.ts`, `settings.query.ts`; `routes/settings/SettingsPage.tsx`, `CartTrackingSection.tsx`, `EmailsSection.tsx`, `RunHistorySection.tsx`; Vitest for the valibot schema (ranges match PHP); form with @tanstack/react-form.
   - Cart tracking: toggle with help text (includes saving guest emails typed at checkout before the order is placed), abandon minutes and capture guest emails (hidden while tracking is off, separators included; shown again as soon as it's turned on; turning it off resets both to their saved values and clears their errors), delete carts after N days (always shown). Emails: unsubscribe text (placeholder = `unsubscribeDefault`), link to `wp.adminUrl + 'admin.php?page=wc-settings&tab=email'`. Run history: keep N days.
   - States: skeleton while loading; toast on save; server `errors[].field` shown on the field; load error with retry.
   - Layout (Jack, Oct 29): Save changes in the app header via a portal into Layout's `#yay-header-actions` (after the theme toggle; Layout unchanged), only on Settings, not while loading or after a failed load; "Save" below sm. Never `disabled` or dimmed: `LoadingButton` spinner while saving, a click during a save returns that running save (no second PUT). Save with nothing changed is a real save (PUT {} → "Settings saved"). No title row; cards fill `PageCard`; error bars at the top.
   - YayUiKits only: `Card` / `CardHeader` (`CardTitle`, `CardDescription`, no icon) / `CardContent`; `FieldGroup` + `FieldSeparator`; each setting `Field orientation="responsive"` > `FieldContent` (`FieldLabel`, `FieldDescription`, `FieldError`) + control; numbers `InputGroup` + `InputGroupInput` + unit `InputGroupAddon align="inline-end"`; unsubscribe `InputGroupTextarea` + counter `InputGroupAddon align="block-end"`; email note `Alert variant="muted"` + Mail icon; error bars `Alert destructive`.
   - Leaving with unsaved changes (dirty = values differ from saved): in-app `useBlocker` + `AlertDialog` "Unsaved changes" · "You have unsaved changes. Do you want to save them before leaving?" · Discard changes (leave) · Save changes (save, leave on success; on failure stay and show the error) · close / Esc = stay. Tab close, reload, other wp-admin pages: `beforeunload` only while dirty. A successful save clears dirty before any later navigation.
   - Browser: 1. Page loads with skeleton then values. 2. Change each field, Save → toast; reload → kept. 3. Abandon 5 → inline error, nothing saved. 4. Tracking off → abandon minutes and capture guest emails hidden while tracking is off; on → shown before saving. 5. Email settings link opens WC Emails. 6. Network offline → save error shown, form keeps edits. 7. Dark admin theme. 8. Leave dirty → dialog (Discard / Save / close); reload dirty → browser prompt.

B2. ✅ **Health check panel** (667b6af) — Risk: 2
   - Files: `packages/types/src/health.ts` (+ index export); `routes/settings/api/health.api.ts`, `health.query.ts`, `request.ts` (error parsing shared with `settings.api.ts`; `workflow.api.ts` unchanged); `routes/settings/HealthPanel.tsx`, `SettingsErrorAlert.tsx` (moved out of `SettingsPage.tsx` for both); Vitest `HealthPanel.test.tsx`.
   - Placement (Jack, Oct 29): last card of Settings after Run history, only in the loaded state; not part of the form (no effect on dirty, Save or the leave guard). Card per mock v3 #settings: CardTitle "Health check", CardDescription = summary, "Check again" as CardAction.
   - Data: query `['health']`, a scan per visit, none on focus or reconnect, `retry: 1`, no ky retries; later scans only on "Check again" (LoadingButton spinner while fetching). The scan reads up to 500 runs, so never polled. One mutation per fixable check: that row's button spins, others stay usable; on success the health query is cancelled (a scan started earlier may predate the fix) and `setQueryData` takes the response's `checks` with `checkedAt` = now on the client; toast. Fix error → toast "Couldn’t fix it. {error}".
   - Rows: problems first (runs_queued, runs_running, cleanup_job), then jobs_late (info, no button, link = `guide`, new tab); ok checks hidden. Words per the mock wording rows "Health check" and "Health check, more (Oct 29, B2)"; counts, help and fix lines `_n`; `capped` reads "N+" (the count plus "+", plural form). Summary: "N problems · checked {time}" / "No problems · checked {time}" (info only) / "Checked {time}" (all ok), {time} = `humanTimeDiff` as in Last run. All ok: "No problems found" block. Toasts: N runs queued again. / N runs queued for recovery. / The daily cleanup is scheduled again. / Nothing to fix any more. (info, fixed 0).
   - States: skeleton rows inside the card; load error = ErrorAlert "Couldn’t run the health check. {error}" + Try again inside the card. Below md the button goes under the text.
   - Browser: CP6 steps 2–6 through the panel instead of the console; Check again; loading and error states; light / dark; 375px.

B3. ✅ **Regression + docs + PR text** (714e44c) — Risk: 1
   - `pnpm build`, `pnpm lint`, `pnpm test`; workflow list / editor / runs untouched; ROADMAP-v1.0 Settings "Today" line updated; ROADMAP-v1.0 › Settings line "Health check is the last card on this page."; ROADMAP-dev M7.6, M7.7 ⏳; `.claude/plans/dev/026-pr-b.md`.

## Settled (review, Oct 27)
1. ✅ Order link writes at once in the order hook (COD / BACS change status before shutdown).
2. ✅ Hash = lines (ids, qty, attributes) + currency; prices / totals refreshed on each write, not part of the hash.
3. ✅ A view only updates this session's existing row; never creates, never binds.
4. ✅ Restore stores the merged cart's hash in the WC session (else the first page view after the redirect would look like a cart change and bind).
5. ✅ Restore of a user's cart (`user_id` > 0) in another session: items merged, no prefill, no binding (D4 "a guest never … a user's cart").
6. ✅ Rekey sets the account email / first name on the moved row.
7. ✅ `woocommerce_order_status_changed` hooked even with tracking off (not a front-end hook; keeps rows right for M8).
8. ✅ Limits and the 3-session cap apply to block capture too; CSRF check only on the classic endpoint (Store API checks its own nonce).
9. 🔄 Changed: IP = `WC_Geolocation::get_ip_address()`, HMAC'd; spoofing it only skips the per-IP limit (D4).
10. 🔄 Changed: the restore handler stays on while tracking is off; it merges and redirects, suppresses tracking, never binds (D6, D8).
11. ✅ Settings PUT rejects out-of-range values (400 per field); reads clamp.
12. ✅ Cleanup stopped by the 20 s budget continues the next day (no follow-up job).
13. ✅ `reset-data.sh` also drops carts, deletes `yaymatik_settings` / `yaymatik_cart_secret` / `yaymatik_cleanup_checked_at`, unschedules `yaymatik_cleanup`.
14. ❌ Rejected (transient cursor): offset for export, stateless re-query for erase, quoted JSON needle (D11).

## Known limits
- A spoofed `X-Forwarded-For` skips the per-IP capture limit and can drain the site-wide one for 15 minutes (decisions M7 Cost).
- Emails taken from pending orders (D7 order created) are not rate limited.
- The capture counters are not atomic: a burst of parallel requests can pass a limit.
- A restored cart moves to the browser that first changes it; the browser it came from starts a new row without its email, unless it opens the link again.
- Restore is one click: a page on another site can make a browser open a valid link; the saved items go into that browser's cart, and an empty guest checkout gets the cart's email.
- Saved lines keep no cart item data, so products with add-on options come back without them.
- The health check reads at most the 500 oldest overdue runs (every running run, queued over 5 min, waiting past 15 min) and looks up jobs once or twice for each; a full read sets `capped: true` and `count` means "at least". Stuck runs newer than those 500 show only once older ones are fixed or finished. `POST /health/fix` reads twice (fix, then the checks it returns) and fixes the first 50 per call.
- `cleanup_job` reschedules a job that is over 1 h late; with WP-Cron broken it is late again later (`jobs_late` says why).
- Cleanup queries scan the runs and carts tables without an index; batches stop early because expired rows are the oldest ids. An index needs a schema change: after v1.0 if a store's tables are large.

## Out of Scope
- M8: triggers, `abandoned` status, abandon sweep job, cart entity, exit condition, email blocks (follow-ups go to ROADMAP-dev M8 in CP7).
- Consent checkbox / opt-in mode (v1.1 backlog line).
- Generated coupons in the eraser.
- Admin list of tracked carts; cart revenue reports.
- `_woocommerce_persistent_cart` usermeta; SameSite on WC cookies.
- Health checks beyond a–d (large tables, SMTP, missing plugin steps).
- Migration of any earlier dev data.

## Meta
- Target branches: PR A `jack/feature/m7a-carts-settings` (from develop 6425140); PR B `jack/feature/m7b-settings-ui` (from develop cf1e841; PR A merged as #30).
- Base branch: develop
- Two PRs; Jack opens them by hand (merge commit) after a zip review.
