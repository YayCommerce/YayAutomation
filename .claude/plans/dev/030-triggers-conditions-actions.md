# Plan: 030 — Triggers, conditions and actions for custom workflows

## Goal
Shop owners can build their own workflows from New Workflow: 6 new triggers, about 25 new condition fields with list, relative date and meta comparisons, one level of condition groups, and 5 new actions, all safe to run unattended.

## Rules for /wdev (from Jack's brief and CLAUDE.local.md)
- One plan, one PR, 3 runs: **run 1 = CP0–CP6, run 2 = CP7–CP10, run 3 = CP11–CP16.** Each run ends with a report and stops. A later session reads only this plan.
- One-pass mode inside a run: after each CP, stop hook green; `/code-review high` on risk 2–3 CPs (fix findings, skip ones about earlier dev data); commit locally by path; mark ✅ + hash here ("✅ **CP2 …** (a1b2c3d)"); go on without waiting.
- Stop and ask only when: tests or lint still fail after two fix attempts; the code contradicts a decision below; an irreversible step is needed (deleting data, `git reset --hard`, a schema change outside this plan); two valid options and this plan doesn't choose. **CP5 and CP6 also stop if their mock section wasn't given.** (Given: mock `#ct-cp5`, `#ct-cp6`.) Small choices: take the simplest, log under "Deviations".
- Commits: stage by path (never `git add -A` / `.`); never `.claude/settings*.json`; no Co-Authored-By / Claude-Session lines; message `feat|fix|docs: plan 030 CPn — …`. Review fixes are new `fix:` commits, never amend or rebase. Never `git stash`.
- Never push or `gh pr create`. Plans stay uncommitted.
- Search every symbol added or removed; delete those without a caller (a test alone is not a caller). `composer dump-autoload` after a new PHP class. `./run.sh meta-fixture` after a catalog or `yayMatikMeta` change.
- A difference from the docs updates the docs in the same commit. Never reword a decision line to pass a grep.
- `./run.sh check-catalog` grows per CP from CP1, so risk 2–3 CPs have a real done-check. CP16 finishes it.
- No other product's name in code or docs. Dates and schedules never in `docs/`.
- UI: YayUiKits only, theme tokens, no `dark:`; read `.claude/docs/context-ui-components.md` before CP4–CP6. Authority: this plan, docs, then the mock (YayMatik Pages v3, https://claude.ai/artifact/R6XzCEZqqyVHBe7uaknMoS).
- Run report, per CP: hash, files, tests, review findings and fixes, deviations. Then: unused code outside the task, browser steps for Jack (no Chrome here). PR text only at the end of run 3, in `.claude/plans/dev/030-pr.md`, with no "Generated with" line.

## Decisions (final, from Jack's brief)
- **D1 Scope** = this list only. Out: HTTP/webhook, Slack, tags, stock trigger, product actions, refund action, change workflow status, YayMatik triggers.
- **D3** `customer.total_spent` / `paid_order_count` (existing key kept, label "Paid order count"): paid statuses only (`wc_get_is_paid_statuses()`); person = user id + billing email, guests by billing email; total_spent minus refunds; currencies summed as stored (help text says so); query only when a rule reads it, cached per slice; HPOS + posts.
- **D4 Order paid:** `woocommerce_order_status_changed` unpaid → paid, first time only, claim in order meta (like OrderCreated). Orders created already paid don't fire.
- **D5** Customer order count reaches / Customer total spend reaches: two triggers, fire on Order paid only when this order crosses the threshold (before < N <= after). Run subject `order:<id>`; new `Trigger::claim_key()` (default `subject()`) = `user:<id>` or `email:` + `hash( 'sha256', lowercased email )`; `claims_per_workflow` via `trigger_claims`. PersonalData finds `email:` claim keys.
- **D7 Review posted:** approved product reviews only (`wp_insert_comment` approved + `transition_comment_status` → approved), type `review`, `comment_parent` 0 only, claim per review; customer = account if `user_id`, else email only (never look up an account by email). New picker category "Reviews".
- **D8 Change customer role:** modes add / remove / replace (default add); replace swaps only unprotected roles; never remove the last role; never assign `administrator`, `shop_manager` or a role with a protected cap (`manage_options`, `manage_woocommerce`, `edit_users`, `promote_users`, `create_users`, `delete_users`, `remove_users`, `list_users`, `install_plugins`, `activate_plugins`, `edit_plugins`, `delete_plugins`, `update_plugins`, `install_themes`, `switch_themes`, `edit_themes`, `delete_themes`, `update_themes`, `edit_theme_options`, `update_core`, `edit_files`, `unfiltered_html`, `unfiltered_upload`, `edit_shop_orders`); never change a user holding one; filter `yaymatik_change_role_allow_all`. Guests skip.
- **D10 Condition groups:** keep `match` + `rules`; a rule may be `{ match, rules }`, one level deep. Old configs and templates stay valid.
- **D11** Update customer custom field + `customer.meta` condition; key input suggests keys used by other workflows.
- **D13** CP0 docs frame; each CP updates its docs in the same commit.
- **D15 / D16** YayMatik module actions: Wait until, Stop waiting runs; Stop waiting runs reuses `PersonalData::subjects()` made shared (no copy).
- **D18** This plan comes before M9 release hardening (now plan 031); docs that name "030" for M9 change to 031.

D2, D6, D9, D12, D14 and D17 are covered by "Out of scope"; nothing else needed.

## Catalog
**Triggers (WooCommerce module):** Order paid; Order refunded (one run per refund; data item `refund`: amount, reason, is_full); Order note added (note type + "contains" fields; skips notes added by YayMatik by default); Customer order count reaches; Customer total spend reaches; Review posted (data item `review`: rating, content, is_verified, product_name).

**New condition fields:**
- Order: `product_ids`, `category_ids`, `coupon_codes` (list); `paid_date`, `completed_date` (date); `billing_country`, `shipping_country`, `created_via`, `currency` (enum); `customer_note` (string); `discount_total`, `shipping_total`, `refunded_total` (number); `is_guest` (bool); `meta`.
- Customer: `total_spent` (number); existing `paid_order_count` now computed by `CustomerStats`; `role` (list); `registered_date`, `last_order_date` (date); `is_guest` (bool); `purchased_product_ids` (list, loaded only when used); `meta`.

**Comparisons:** list `matches_any` / `matches_all` / `matches_none`; enum `is_any_of`; date `in_last` / `not_in_last` (N hours|days), `is_set` / `is_not_set`; meta = key + string / number operators. No regex. Paged REST search for products, categories, coupons (never in `yayMatikMeta`). Operators in PHP and TS.

**Actions:** fix Update order custom field; Update customer custom field; Change customer role; Resend WooCommerce email; Wait until; Stop waiting runs.

**Safety:** write actions run as user 0, so guards run at run time too; write actions output what changed, never rendered values (runs keep refs only); order writes add a note. Each action declares `emits()`; the Validator warns, not blocks, when an action can re-fire its own workflow's trigger.

**Performance:** active-trigger cache = autoloaded option of trigger ids with an active workflow, rewritten after activate / deactivate / apply / delete; Dispatcher listens only to those; memo `active_for_trigger`.

## What research changed (read this first)
Each item adjusts the brief. Say so in the run report. Jack reviewed all of them; items 1, 3, 5, 13 and 16 hold his changes.

1. **Orders created already paid DO fire the hook (D4).** A new `WC_Order` has no stored status, so `get_status()` reads `pending` (`abstract-wc-order.php:658–669`); `set_status()` then records `from` = pending (`class-wc-order.php:319–328`), and `save()` runs the transition (`:285–286`, `:477–478`). So `wc_create_order( [ 'status' => 'processing' ] )` fires pending → processing. **Fix:** `woocommerce_new_order` fires inside the first save, before the transition (`OrdersTableDataStore.php:3029`, `class-wc-order-data-store-cpt.php:117`). A listener remembers order ids whose status is already paid there, and the status event for those ids is dropped in that request. Only an order saved already paid in its first save (imports, `wc_create_order` with a paid status) doesn't fire. Orders created through admin (auto-draft first; `set_status` reads it as pending, `abstract-wc-order.php:919–930`) and REST `set_paid` or a gateway (a later save) DO fire. Both stores tested in CP7.
2. **"First time only" can't see the time before the trigger was listening.** The claim meta is written only while an Order paid workflow is active (Dispatcher: no workflows → no claim, and with the cache no listener). An order paid before activation that goes unpaid → paid again starts once. Known limit; `date_paid` can't tell, as it's set before the event (`class-wc-order.php:334`).
3. **`customer.order_count` duplicates the existing `customer.paid_order_count`.** Same person, same statuses (`PAID_STATUSES` = the default of `wc_get_is_paid_statuses()`, `wc-order-functions.php:134`). Two fields for one fact would confuse owners. **Decided (Jack):** keep the key `customer.paid_order_count`, label "Paid order count"; no `customer.order_count`, no rename, no template change, no dev reset. `CustomerStats` computes it, still a float (`check-lifecycle.php:444,448` read `0.0` / `1.0` and stay as they are). `PAID_STATUSES` goes; `find_paid_orders()` and `is_first_order` read `wc_get_is_paid_statuses()`.
4. **`is_internal_meta_key()` is protected (`abstract-wc-data.php:383`), and false when a key has no getter or setter (`:390–393`).** It can't be called from a step. **Fix:** `$order->get_data_store()->get_internal_meta_keys()` is public (`class-wc-data-store-wp.php:560`, reached through `class-wc-data-store.php:220`; HPOS inherits it, `OrdersTableDataStore.php:29`, `:182`). Compared lowercase: `meta_key` collation is case-insensitive.
5. **Resend: "customer email + `trigger($order_id)`" isn't enough.** Several customer emails take other arguments: fulfillment `( $order_id, $fulfillment, … )`, POS `( $order_id, $template_id )`, note `( $args )`, new account `( $user_id )`, reset password, withdrawal `( array, int )`. Calling those is a fatal `ArgumentCountError` or a wrong email. **Decided (Jack):** options = an allowlist of core ids: `customer_processing_order`, `customer_completed_order`, `customer_on_hold_order`, `customer_invoice`, `customer_cancelled_order`, `customer_failed_order`, `customer_review_request` (each id checked in its class), through filter `yaymatik_resendable_emails` (array of ids). Keep only ids present in `get_emails()` (`class-wc-emails.php:347`, keyed by class name, so match `$email->id`) with `is_customer_email()`. No `ReflectionMethod`. Refunded stays out: a resend would always use the full-refund text (`class-wc-email-customer-refunded-order.php:193`; known limit). An id a plugin adds with another `trigger()` signature fails that step: `Executor::run_step` catches it.
6. **Stop waiting runs must not use `WorkflowStore::all()` for its options.** `Field::known()` reads options on every validate, and `all()` decodes and sanitizes every graph. `Validator::list_issues()` per workflow on `GET /workflows` makes that O(n²) (`WorkflowStore.php:15`, `:454–459`). **Fix:** new `WorkflowStore::names(): array` (id => name, one small query). Same list, same behaviour.
7. **A failed Stop step shouldn't end the run.** A deleted target gives the step issue through `known()` (as asked), and at run time `Executor::run_step` fails the step on invalid settings. **Fix:** `on_error()` = `ON_ERROR_CONTINUE`: run history shows the failed step and the rest of the run goes on.
8. **`search_select` is not a step `Field`.** Only condition values use it. A `Field::search_select()` would have no caller (CLAUDE.md scope rule). **Fix:** data field prop `search` (`product` | `category` | `coupon`), sent as `search` in `DataRegistry::to_client()`, drawn by a `SearchSelect` widget in `ValueInput`.
9. **Rule values aren't always strings now.** `Criteria::sanitize()` runs `self::scalar()` and TS has `value: v.string()`. Shape below. A flat old rule must come out byte-identical, or `GraphSchema::same()` sees a change and old drafts look edited.
10. **`Field::is_blank()` and TS `isBlank()` read `rule.field`.** A config holding only groups would read blank. Both go recursive in CP6, and so do `Criteria::validate`, `uses`, `evaluate` and the TS `schema`, `uses`, summary.
11. **Date values are `Y-m-d`, so "in the last 3 hours" can't use them.** Changing the value would break `before` / `after`. **Fix:** optional field prop `timestamp` ( fn( $object ): ?int ); `Scope::timestamp( $path )`. A date field without it counts as 00:00 store time on its date.
12. **The claim's release uses the run's subject (`Worker.php:219`).** With D5 the claim key isn't the subject, and a run row has no args. **Fix:** milestone `refs()` also stores plain `claim_key`; Worker releases `$run['scope']['trigger']['claim_key'] ?? $run['subject']`. Not offered as a variable. A guest's key is a hash (item 13), so the claim key puts no plain email in `runs.scope`.
13. **`trigger_claims.subject` is `varchar(100)`, an email can be 254.** `INSERT IGNORE` would truncate. **Decided (Jack):** guest claim key = `email:` + `hash( 'sha256', lowercased email )`, 70 characters. No schema change, no cut. One helper, `TriggerClaimStore::email_key( string $email )`, is used by the milestones and by `find_keys` / `delete_keys`, which take the email and hash it. No plain email in `trigger_claims`, or in `runs.scope` from the claim key.
14. **Dev scripts and any same-request activation fire events after `init`.** `check-*.php` activate workflows, then create orders, in one process. With "listen only to active" they'd get no runs. **Fix:** `WorkflowStore` fires `yaymatik_active_triggers_changed` after a change; `Dispatcher::listen_active()` hooks it and adds listeners it lacks.
15. **The memo goes per `flush()`, not per process.** `wp action-scheduler run` is one long request; a process-wide memo would miss a workflow activated meanwhile for its whole life. `InactiveSweep` flushes per batch, so one memo per `flush()` covers the bulk case. `Workflows` stays as it is.
16. **Stale cache race.** With a delete-and-rebuild cache, a reader that computed before an activate's commit could write the old list after the clear and hide a trigger. **Decided (Jack):** writers (`activate`, `deactivate`, `apply_draft`, `delete`) query after commit and `update_option()` the fresh list, under one shared lock around the query and the write, so two writers on different workflows can't cross. Lock not taken or a query error: log it and `delete_option()`, so the next reader recomputes; that is the only delete. A reader with no option computes and `add_option()`s, which never overwrites. `Cleanup::run()` recomputes daily. A reader's query error isn't stored, and that request listens to all triggers.
17. **A note written by a run:** a WooCommerce status note has no YayMatik marker (`add_status_transition_note`, `class-wc-order.php` inside `status_transition()`). So "added by YayMatik" = `Runtime::worker()->current()` is set when the note hook fires, stored in the event args.
18. **`is_full` must stay true to the refund's time.** A later refund or a Wait mustn't change it, and `woocommerce_order_is_partially_refunded` runs in `wc_create_refund` only (`wc-order-functions.php:717–727`). **Fix:** read it statelessly: order total minus refunds with id ≤ this one is ≤ 0 (`get_refunds()`, `class-wc-order.php:2208`). A filter that overrides the WooCommerce decision isn't followed (known limit).
19. **Milestones in one request.** Two paid orders of one person in one request: each reads the same "after", so a threshold between them can be missed. Known limit (bulk edit only).
20. **Stats cache:** PHP 7.4 has no `WeakMap`. `CustomerData` holds a one-slot memo: last customer object + its stats, by identity. A new `RefResolver` per slice gives a new object, so a Wait reads fresh numbers.
21. **`StepResult::wait()` takes no outputs,** and Wait until must output `until`. **Fix:** `wait( int $until, array $outputs = [] )`. `Executor.php:263` already keeps wait outputs; `:240` finishes with them.
22. **Preview needs no change for refund and review.** Their `refs()` without `refund_id` / `review_id` return null, so `EmailPreview::values()` (`:196`) uses `DataItem::sample()` for everything they provide, with the Sample badge. Tested in CP8 and CP10.
23. **D18:** `grep` finds no "030" for M9 in `docs/` or `.claude/docs/`. Only private notes named it, and they already say 031. CP0 has nothing to rename; the grep stays as its done-check.
24. **M6 still shows ⏳ in ROADMAP-dev though #35 merged.** CP0 fixes it.
25. **Product list fields use the parent id** (`get_product_id()`), so search returns parents only and a variation matches its parent's rule. `category_ids` uses `wc_get_product_cat_ids()`, which adds ancestors (`wc-product-functions.php:1253`).
26. **Rule summaries on the canvas never call REST.** Search lists read "Products in order matches any of 3 selected". The form loads names through `include[]`; a deleted id shows `#12`.

## Decisions against docs and code
Every "differs" updates its doc in the CP named.

| Decision | Result | Note | Docs in |
|---|---|---|---|
| D1 Scope | Follows ROADMAP-dev v1.2 (HTTP, connectors later) | Silent on the 6 triggers | CP0 |
| D3 Stats | Docs silent. Differs from code: `PAID_STATUSES`, how `paid_order_count` is computed | Items 3, 20 | CP3 |
| D4 Order paid | Follows design §3 claim pattern (`OrderCreated::claim()`). WooCommerce differs from the brief | Items 1, 2 | CP7 |
| D5 Milestones | **Differs:** design §2 and §3 say the claim is per workflow and **subject** | Items 12, 13, 19 | CP9 |
| D7 Reviews | Follows design §4 (account vs email customer, never an account by email). **Differs:** §4 Scope list, §5 picker sections, §1 DataItems list | | CP10 |
| D8 Role | Docs silent | | CP12 |
| D10 Groups | **Differs:** design §5 calls rules flat through `Criteria` | Item 10 | CP6 |
| D11 Customer field | Docs silent; ROADMAP-v1.0 Editor "Today" lists 4 actions | | CP11 |
| D13 Docs frame | Follows CLAUDE.md "Document only what exists" for design; plans go in ROADMAP-dev | | CP0 |
| D15 / D16 | Follows §8 (YayMatik module owns logic). **Differs:** PersonalData owns `subjects()`; moves to Core | | CP15 |
| D18 | Nothing to rename | Item 23 | CP0 |
| Field type `search_select` | **Differs from the brief**, and from §5 "Field vocabulary (kept small)" only by not adding a word | Item 8 | CP4 |
| List / relative date / meta types | **Differs:** §4 Types, `DataItem` docblock | Items 9, 11 | CP2, CP3 |
| Active-trigger cache | **Differs:** §3 Dispatcher "match at shutdown" over every trigger | Items 14–16 | CP1 |
| `emits()` + warning | **Differs:** §5 contract; `Validator` only knows blocking issues | | CP1 |
| Order meta guard | Docs silent. Differs from the brief's method name | Item 4 | CP1 |
| PHP-only step checks | Follows §5 "show up as the step's issue after a save"; `StepForm` draws them through `FieldIssueContext`. **Differs:** dev convention line "needs a matching TS form" — already untrue for Generate coupon | Convention line corrected | CP1 |

## Code facts (WooCommerce 11.2.1, WordPress source checked)
- `woocommerce_order_status_changed` fires only with a non-empty `from`, after the status note: `class-wc-order.php:477–478`.
- `wc_create_refund`: amount `:589`; `set_total( -amount )` `:661`; partial or full `:717–727`; parent status change `:737–741`; order saved `:749`; `woocommerce_refund_created( $refund_id, $args )` `:751`. `wc-order-functions.php`. `get_amount` / `get_reason`: `class-wc-order-refund.php:95`, `:106`.
- `woocommerce_order_note_added( $note_id, $order )`: `class-wc-order.php:2144`, after `is_customer_note` meta `:2117`.
- `wp_insert_comment( $id, $comment )` `wp-includes/comment.php:2234`, before `comment_post` `:2491`, where WooCommerce adds `rating` (`class-wc-comments.php:41`, `:256`) and `verified` (`:84`, `:521`). Read them at shutdown, never in the hook. `transition_comment_status( $new, $old, $comment )` `comment.php:1959`, only when the status changes. `comment_type` becomes `review` in `preprocess_comment` (`class-wc-comments.php:87`, `:656–659`).
- Paid list: `wc_get_is_paid_statuses()` `wc-order-functions.php:134`. WooCommerce's own totals don't subtract refunds and only know accounts (`class-wc-customer-data-store.php:495–…`), so `CustomerStats` has its own SQL.
- HPOS: `OrderUtil::custom_orders_table_usage_is_enabled()` `src/Utilities/OrderUtil.php:39`; `billing_email` filter `OrdersTableQuery.php:1152`, `:1240`.
- `send_notification()` does nothing when the email is off (`class-wc-email.php:1167`) or has no recipient (`:1180`), and `trigger()` returns nothing. So the step checks `is_enabled()` and the billing email first.
- `update_meta_data()` on an internal key calls its setter (`abstract-wc-data.php:519–520`).
- `Dispatcher::register()` runs at `init` priority 0 (`Runtime.php:36`). Trigger listeners hold no duties beyond emitting, except `CustomerAccountCreated` (`saved_customers`), which is only read in its own `claim()`.
- `RunStore::waiting_ids_for_subjects()` `RunStore.php:252`; `Cancellation::cancel()` is private (`Cancellation.php:67`).
- `steps/` may not import `api/` (dev convention): `SearchSelect` has its own fetch.
- The picker's labels are `categoryLabels` in `pages/editor/StepPicker.tsx:25`, typed from `StepMeta['category']`.
- Parity: `steps/moduleSteps.test.ts`, `ModuleRegistryTest::STEPS`, `StepContractTest` outputs map, `TriggerRefsTest`, `SampleValuesTest`, `CatalogTest`.
- `dev/scripts/reset-data.sh` lists options by hand (`:26`). `run.sh` finds any `dev/scripts/<cmd>.sh`.

## Design

### Rule shape (CP2, CP3, CP6)
```
Rule  = { field, operator, value: string | string[], valueType: 'value' | 'variable', unit?: 'hours' | 'days', key?: string }
Group = { match: 'all' | 'any', rules: Rule[] }          // CP6, inside a condition's rules only
```
- `value` is an array only for `matches_*` and `is_any_of`. `unit` only for `in_last` / `not_in_last`, with `value` a whole number ≥ 1. `key` only on meta fields. Other keys are dropped by `sanitize`, so a flat old rule is unchanged (item 9).
- A variable as the value: number, string and date with `before` / `after` / text / number comparisons only, as today. Never for list, `is_any_of`, relative dates or meta.
- A group inside a group is dropped by `sanitize`. An empty group gives `rulesRequired`.

### Operators (CP2)
`DataItem::TYPE_LIST`, `TYPE_META`. `BY_TYPE`: list `matches_any, matches_all, matches_none`; enum adds `is_any_of`; date adds `in_last, not_in_last, is_set, is_not_set`; meta = string's + number's (`equal, not_equal, contains, not_contains, starts_with, ends_with, is_empty, is_not_empty, greater_than, greater_or_equal, less_than, less_or_equal`). `NO_VALUE` adds `is_set`, `is_not_set`; TS `needsValue` mirrors.
- List: lowercased, trimmed strings. Empty actual: `matches_none` true, others false. Empty expected is a rule error.
- `is_any_of`: `in_array` lowercased.
- `Operators::in_last( ?int $at, int $amount, string $unit, int $now ): bool`: `$at` set and `now - span <= at <= now`. `not_in_last`: set and older. **No date never matches either**, like `before` / `after`; owners use a group: `not_in_last 30 days` OR `is_not_set`.
- Meta: `equal` / `not_equal` compare as numbers when both are numeric ("5.0" = "5"), else as text. A number operator with a non-numeric side is false. Array or object meta reads as `''`.
- `Type::from_condition_type`: list → `list<string>`; meta → string.
- Fixed unit seconds (hours 3600, days 86400), like Wait.

### DataItem props (CP2–CP4)
- `timestamp` fn( $object ): ?int (item 11). `Scope::timestamp( string $path ): ?int`.
- `search` `product|category|coupon`; to client.
- `meta` fn( $object, string $key ): string; `Scope::meta( string $path, string $key ): string`.
- `merge_tag` bool, default true; false = not offered by Insert variable and not a known tag (`Validator::entity_variables`, `has_field`, TS `variables.ts`). Meta fields are false.
- A list field's `display` is names joined by ", " (products and categories) or the codes.

### MetaKeys (CP1, CP3, CP11)
New `Core/Data/MetaKeys`.
- CP1 `order_blocked( \WC_Order $order, string $key ): bool`: internal keys lowercase (item 4), or lowercase prefix `_yaymatik_`.
- CP3 `user_blocked( string $key ): bool`: `session_tokens`, `/^{base_prefix}(\d+_)?(capabilities|user_level|user-settings|user-settings-time)$/i`, prefix `_yaymatik_`. The prefix is `preg_quote`d.
- CP11 `user_collides( string $key ): bool`, for writes: `SELECT DISTINCT meta_key FROM usermeta WHERE meta_key = %s`; true when any row found is `user_blocked`, so `WP_CAPABILITIES` or `wp_capabilitiés` under a loose collation can't reach a protected row.
- Conditions use `order_blocked` / `user_blocked` on read too (`ruleMetaKey` message), which also avoids `wc_doing_it_wrong` on `get_meta()` of an internal key (`abstract-wc-data.php:401`).

### Active-trigger cache (CP1)
- `WorkflowStore::active_trigger_ids(): ?array`: autoloaded option `yaymatik_active_triggers`. Missing: `SELECT DISTINCT trigger_id … WHERE status = 'active'`, `add_option( …, '', 'yes' )`, which never overwrites. Query error: return null, not stored; the Dispatcher then listens to all.
- `WorkflowStore::refresh_active_triggers()`: `GET_LOCK( $wpdb->prefix . 'yaymatik_active_triggers', 5 )` in the style of `WorkflowStore::locked()`, `RELEASE_LOCK` in `finally`; inside it the same query and `update_option( …, true )` with the fresh list. Lock not taken or a query error: `Logger::error` and `delete_option()`, so the next reader recomputes with `add_option()`. Then `do_action( 'yaymatik_active_triggers_changed' )`. Called after a successful transaction in `activate`, `deactivate`, `apply_draft`, `delete`. `update_live` needs none: only a never-activated, inactive workflow edits its live graph.
- `Workflows` gains `active_trigger_ids(): ?array`; `RepositoryWorkflows` delegates; `FakeWorkflows` computes.
- `Dispatcher::register()`: `add_action( 'yaymatik_active_triggers_changed', [ $this, 'listen_active' ] )`, `listen_active()`, shutdown flush. `listen_active()`: for each trigger whose id is active and not in `$this->listening`, call `listen()`. A listener stays until the process ends; `dispatch` finds no workflow and stops.
- `Dispatcher::flush()`: `$this->active = []` first; `dispatch` reads `$this->active[ $id ] ??= active_for_trigger( $id )`.
- `Cleanup::run()`: `WorkflowStore::refresh_active_triggers()` daily. `reset-data.sh`: add the option.

### emits() and warnings (CP1)
- `Action::emits(): array` = trigger ids this action can cause; default `[]`. CP1: `ChangeOrderStatus` → `order_status_changed`. CP7 adds Order paid and note ids; CP9 the milestones. CP12 and later add none.
- `Trigger::fires_for_own_runs( array $config ): bool` default true.
- `Validator::warnings( array $graph ): array`: `[ code: 'may_refire', nodeId, message ]`, one per action whose `emits()` holds the graph's trigger id and `fires_for_own_runs()`. `Messages` key `mayRefire`, in TS `messages.ts`.
- REST `with_issues()`, list, get: `warnings: { live, draft }`, never throws (same guard as `list_issues`). Never blocks activate.
- TS: `Workflow.warnings`; `editedWarnings()` in `model/validation.ts`; `StepPanel` shows a warning line for its node with `WarningBadge`. Read from the last server check, like `withServerIssues`. No mock frame: smallest thing that reads right; Jack checks it in the browser.

### Fix Update order custom field (CP1)
`UpdateOrderMeta::validate()`: `field_error`, then `MetaKeys::order_blocked( new \WC_Order(), $key )`, as a blank order has the same data store. `run()` checks again on the real order. Blocked → `StepResult::failed`. Outputs `key`, `changed` (old != new; no values); non-scalar old reads as `''`. Run detail: "Custom field gift_wrap updated" / "unchanged". Old → new only in a private note "Custom field %1$s changed from “%2$s” to “%3$s” by a YayMatik workflow." `emits()` adds the note id in CP7.

### Search (CP4)
`Engine/API/SearchRoutes.php`, boot beside `HealthRoutes` (`YayMatik.php:27`, WooCommerce only), `can_manage`.
`GET /search/(?P<type>products|categories|coupons)`, `term`, `page`, `include[]`; 20 per page. Response `{ items: [ { value, label } ], hasMore }`.
- products: `WP_Query` `product`, `s`, status publish + private + draft; value id. No variations (item 25).
- categories: `get_terms` `product_cat`, `search`, `number`, `offset`; value term id.
- coupons: `WP_Query` `shop_coupon`, `s`; value `wc_format_coupon_code( title )`; label the code.
- `include[]`: exact items for loading a saved rule's labels.

TS `steps/core/fields/searchSelect.query.ts` (own fetch, TanStack Query, debounced `term`), `SearchSelect.tsx` built on the kit (see the UI doc): chips and the search input in one field, the list below (mock "Search open"). Load more through paging.

CP5 adds `GET /search/customer-meta-keys`: keys from `customer.meta` rules (groups too) over `WorkflowStore::all()`; CP11 adds the keys of `update_customer_meta` steps.

### Condition form (CP5, CP6)
Stop if no mock section. `ValueInput` branches: enum + `is_any_of` → `MultiSelect`; list with options → `MultiSelect`; list with `search` → `SearchSelect`; `in_last` / `not_in_last` → number + unit select; meta → key input with the meta-keys query's names listed under it ("Used in your workflows", `KeySuggestInput`), then the value input by operator. `RuleBox.chooseField` keeps value and unit only when the shape still fits; a new shape clears them.
`criteriaErrors` and PHP `rule_error` gain, same order: `ruleMetaKey`, `ruleKeyRequired`, `ruleAmount` (whole number ≥ 1), `ruleListRequired`. Each in `Messages` and `messages.ts`.

CP6: `Group` box with its own all / any select, joiner, "Add group", at most one level. Errors per rule in groups. Summary "(A or B) and C".

### CustomerStats (CP3)
New `Core/Data/CustomerStats`, the query behind fields and milestones.
- `totals( int $user_id, string $email ): array` `count`, `spent`, `last_paid` (Unix, or null).
- `product_ids( int $user_id, string $email ): string[]`: parent ids from `woocommerce_order_itemmeta` `_product_id`, `line_item`, over the paid orders.
- Paid set = `type shop_order`, status in `wc-` + `wc_get_is_paid_statuses()`, `customer_id = user` (when > 0) OR billing email = email (when not empty). Both empty → zeros, no query.
- `spent` = SUM total of the set + SUM refund totals (`type shop_order_refund`, `parent_order_id` in the set; stored negative, `wc-order-functions.php:661`).
- HPOS over `wc_orders`; posts over `posts` + `postmeta` (`_customer_user`, `_billing_email`, `_order_total`; refund posts `post_parent`). Picks the store with `OrderUtil::custom_orders_table_usage_is_enabled()`. Currencies summed as stored; help text on the field.
- `CustomerData` constructor gains `?callable $stats`, tests fake it; one-slot memo (item 20). `paid_order_count` = `(float) count`, label "Paid order count"; `total_spent` = `spent`. `is_first_order` stays on `paid_ids()`, and `find_paid_orders()` reads `wc_get_is_paid_statuses()`.
- New public `CustomerData::user_id( $customer ): int`, used by stats, role, meta and the actions. Order → `get_customer_id()`, else `get_id()`.

### Triggers (CP7–CP10)
Shared: new abstract `Triggers/OrderPaidTrigger extends OrderTrigger`:
- `listen`: `woocommerce_new_order` (remember already-paid ids, item 1); `woocommerce_order_status_changed` priority 30: `from` not paid, `to` paid, id not remembered → `$emit( [ 'order_id' ] )`. Admin-created orders (auto-draft first) and REST `set_paid` fire; only orders saved already paid in their first save (imports, `wc_create_order` with a paid status) don't.
- `claim` / `matches`: the order must still be paid at shutdown.

| Trigger | id | Category | Notes |
|---|---|---|---|
| Order paid | `order_paid` | order | `META_FIRED = '_yaymatik_order_paid'`, in `claim()` like `OrderCreated`. No fields |
| Order refunded | `order_refunded` | order | `extends OrderTrigger`. Listens `woocommerce_refund_created`. Args `order_id`, `refund_id`. `provides` + `refund`. `refs` / `context` + refund; null without a refund. No claim: one event per refund. No fields |
| Order note added | `order_note_added` | order | `extends OrderTrigger`. Args `order_id`, `note_id`, `by_run`. Fields below |
| Customer order count reaches | `customer_order_count_reaches` | customer | `extends OrderPaidTrigger`. Field `count`, whole number, min 1, default 5, `sanitize` rounds (win-back pattern) |
| Customer total spend reaches | `customer_total_spend_reaches` | customer | `extends OrderPaidTrigger`. Field `amount`, number, min 0.01, help says currencies are summed as stored |
| Review posted | `review_posted` | review | Below |

**Order note added fields:** `note_type` select (`any` default, `private`, `customer`); `contains` text, case-insensitive plain text, empty = any, read with tags stripped; `skip_yaymatik` toggle, default on. `fires_for_own_runs( $config )` = `! skip_yaymatik`. `context` adds the type and text from the comment; gone → null. With the toggle off, a run's own note can start a workflow, and the `in_chain` loop guard still stops a workflow restarting itself.

**Milestones (D5):** `Trigger::claim_key( array $args ): string` default `subject()`. `Dispatcher::dispatch`: claim and release with `claim_key`. `context()` adds `after_count`, `after_spent`, `this_count` 1, `this_spent` = this order's total − refunded, from `CustomerStats::totals()` once per event. `matches`: `before < N && N <= after`, where before = after − this. `claims_per_workflow()` true. `claim_key`: `user:<id>`, else `TriggerClaimStore::email_key( billing email )` (item 13). No user and no email: `context` null. `refs` add the hashed `claim_key` (item 12).
`TriggerClaimStore`: `email_key( string $email ): string`; `find_keys( string $email ): array`, `delete_keys( string $email ): int`, both hash the email. `PersonalData`: export page 1 lists a "Customer milestones" item when any; erase's last page deletes them.

**Review posted (D7):** `Triggers/ReviewPosted extends Trigger`. `listen`: `wp_insert_comment` when approved `1`; `transition_comment_status` when new = `approved`. Both: `comment_type` `review`, `comment_parent` 0 → `$emit( [ 'review_id' ] )`. `claim()`: comment still approved. `claims_per_workflow()` true, `subject` `review:<id>`. `provides`: `review`, `customer`. Without `review_id`, `refs` null.
`Core/Data/EmailCustomer` (`get_id` 0, `get_billing_email`; first name, last name, phone, country `''`). `RefResolver`: customer kind `review:<id>`: comment's `user_id` > 0 → `account()`, else `EmailCustomer`. Loaders `review` (`get_comment`, type check) and `refund` (`wc_get_order` instanceof `\WC_Order_Refund`).
`RunPresenter::subject`: `review` → label "Review #%d" and URL `admin_url( 'comment.php?action=editcomment&c=<id>' )`, not `get_edit_comment_link()`, which is empty for a user who can't edit it (same reason as the `user` case); null once gone.
`PersonSubjects` (below) adds `review:<id>` by author email and by the user id of that email's account.

**Data items:**
- `DataItems/RefundData`: `amount` (number, money, parent currency display), `reason` (string), `is_full` (boolean, item 18). Sample values.
- `DataItems/ReviewData`: `rating` (number, `get_comment_meta`), `content` (string), `is_verified` (boolean, `verified` meta), `product_name` (string). Sample values. Registered in `WoocommerceModule::data_items()`.

### Actions (CP11–CP15)
All in `Actions/`. All guard in `run()`.

| Action | id | Module | Category | Requires | Outputs |
|---|---|---|---|---|---|
| Update customer custom field | `update_customer_meta` | WooCommerce | new `CATEGORY_CUSTOMER` | `customer` | `key`, `changed` |
| Change customer role | `change_customer_role` | WooCommerce | customer | `customer` | `from`, `to` (roles joined by ", ") |
| Resend WooCommerce email | `resend_wc_email` | WooCommerce | email | `order` | `email` |
| Wait until | `wait_until` | YayMatik | flow | — | `until` (date type, store time, `Y-m-d H:i`) |
| Stop waiting runs | `stop_waiting_runs` | YayMatik | flow | — | `stopped` (number) |

**Update customer custom field (D11):** fields `key` (text, required; `Field::suggest( 'customer-meta-keys' )`, a new modifier sent as `suggest`, drawn by `TextField` as a list under the input (`KeySuggestInput`)), `value` (text, variables). `validate`: `user_blocked`. `run`: `user_collides` → failed; no user → skipped "This customer has no account."; `update_user_meta`. No note (no order); no values in the outputs. Run detail: "Custom field vip updated" / "unchanged".

**Change customer role (D8):** fields `mode` select `add` default, `remove`, `replace`; `role` select of `assignable_roles()` (roles not `administrator` / `shop_manager`, with no protected cap; the filter opens it), required. `CONST PROTECTED_CAPS`. `validate`: role allowed. `run`, all at run time, in order: no user → skipped; user has a protected cap (`allcaps`) → skipped, "This customer has a protected role."; role gone or now protected → failed; `remove` of the last role → skipped "… is their only role."; `replace`: `remove_role` each unprotected role, `add_role` the new one; a role that wouldn't change → ok, no write. Filter `yaymatik_change_role_allow_all` (bool, default false) lifts the assign and holder checks; last-role stays.

**Resend (item 5):** `CORE_EMAILS` = the 7 ids; `emails()` applies filter `yaymatik_resendable_emails`, keeps ids in `get_emails()` with `is_customer_email()`, returns id => title. Field `email` select of `emails()`. Run: email gone → failed; `is_enabled()` false → skipped "… is turned off in WooCommerce."; no billing email → skipped; `$email->trigger( $order->get_id() )`.

**Wait until (D15):** fields `hour` select 0–23 (labels by site time format, default `9`), `weekdays` multiselect Mon–Sun (ISO 1–7, empty = any, placeholder "Any day"). Generic `StepForm`. Pure `next_time( int $now, int $hour, int[] $days, \DateTimeZone $zone ): int`: `DateTimeImmutable` in `wp_timezone()`, today at `hour:00`, then forward by calendar day up to 8 tries until later than `$now` and on a chosen day. Calendar arithmetic, so DST is safe: a gap moves forward (PHP), an overlap takes the first. `run`: `StepResult::wait( $until, [ 'until' => … ] )` (item 21).

**Stop waiting runs (D16):** fields `workflows` multiselect of `WorkflowStore::names()` (item 6), required; `email` text, variables, required, default `{{ customer.email }}`. `on_error` continue (item 7). `run`: email normalized by `UnsubscribeLink::normalize`; invalid or empty → skipped; subjects = `PersonSubjects::for_email()`; `Cancellation::for_subjects( $subjects, $workflow_ids, $reason )`; reason "Stopped by workflow “%s”." with the running run's own workflow name.
- `RunStore::waiting_ids_for_subjects( array $subjects, array $workflow_ids = [] )`.
- New `Core/Runtime/PersonSubjects::for_email( string $email ): string[]` holds the body of `PersonalData::subjects()`. `PersonalData` calls it; the private method goes.

### check-catalog (CP1–CP16)
`dev/scripts/check-catalog.{sh,php}`, same shape as `check-winback` (removes its rows, safe twice). Runs through real orders, comments and users on the current store.

## Known limits (docs, each in its CP)
- Items 2, 5 (refunded emails), 18, 19.
- Active-trigger cache: if the lock wait passes 5 s, a slow writer or a reader that computed earlier can leave a trigger out until the next change or the daily recompute.
- Order paid: orders created through admin (auto-draft first) and REST `set_paid` DO fire; only orders saved already paid in their first save (imports, `wc_create_order` with a paid status) don't.
- Customer stats scan unindexed `_billing_email` on posts stores, only when a rule or milestone reads them.
- Guest then account: same person counted, two claim keys, so a threshold can start twice only if refunds took them back below it.
- Currencies summed as stored.
- A process that started before an activation (a long Action Scheduler batch) misses that trigger's events until it ends.
- A review's guest customer has only an email.
- Wait until's hour in a DST gap runs at the shifted hour.
- Update customer custom field writes one value, never arrays.

## Checkpoints
Branch `jack/feature/030-catalog` from develop `f747ff8`. PHP paths under `includes/Workflow/` unless shown; TS under `apps/admin-page/src/routes/workflows/`.

### Run 1

0. ✅ **CP0 Docs frame** (ff2aff2) — Risk: 1
   - Files: `docs/ROADMAP-dev.md` (build order `… M6 → CT → M9`; phase table row "CT Custom workflow catalog"; new `## CT` section with items per CP, all "—"; M6 and its items ✅, PR #35 `f747ff8`; v1.1 Backlog adds: run limit per workflow, customer tags, stock triggers, Workflow run failed trigger, random split, multi-currency totals); `docs/ROADMAP-v1.0.md` (milestones table row, build order); `docs/workflow-core-decisions.md` (new "CT. Custom workflow catalog": D1, D3, D4, D5, D7, D8, D10, D11, D15, D16 with why, Contents row); `docs/workflow-core-design.md` (§11 row, "All confirmed" line, one planned-work line in §3, §4, §5 only).
   - Tests: none; docs only.
   - Done: `grep -rn "\b030\b" docs .claude/docs` no M9 hit; `grep -n "⏳" docs/ROADMAP-dev.md` no M6 line; no date, no other product's name.
   - Browser: none.

1. ✅ **CP1 Hardening: order meta guard, active-trigger cache, emits() + warning** (e9aa3e0) — Risk: 3
   - Files: new `Core/Data/MetaKeys.php`; `Actions/UpdateOrderMeta.php`, `Actions/ChangeOrderStatus.php`; `Core/Steps/Action.php`, `Core/Steps/Trigger.php`; `Core/Storage/WorkflowStore.php`; `Core/Runtime/Workflows.php`, `RepositoryWorkflows.php`, `Dispatcher.php`, `Cleanup.php`; `Core/Definition/Validator.php`, `Messages.php`; `Engine/API/RestAPI.php`; `tests/Support/FakeWorkflows.php`; `dev/scripts/reset-data.sh`; new `dev/scripts/check-catalog.{sh,php}`. TS: `model/validation.ts`, `model/messages.ts`, `pages/editor/StepPanel.tsx`, `packages/types/src/workflow.ts`.
   - PHPUnit: `DispatcherWorkerTest` (listens only to active; `listen_active` adds a trigger once; null list → all; memo: 2 events → 1 lookup; a new `flush()` reads again); `ValidatorTest` (`may_refire` on Order status changed + Change order status; none otherwise; not in `errors()`); `StepRunTest` / `StepConfigsTest` (blocked key; `_ORDER_TOTAL` blocked; `_yaymatik_x` blocked; `key`, `changed`; a note).
   - Vitest: `validation.test.ts` `editedWarnings`; `StepPanel.test.tsx` shows the line.
   - check-catalog: option built on read with `add_option`; rewritten fresh, not removed, by each of activate, deactivate, apply, delete; a reader that computed before an activate can't hide the new trigger (simulate: compute the list, activate, then the reader's `add_option` → the new trigger stays); two refreshes interleaved can't leave B's trigger out (simulate: writer A holds the lock from a second process, as `GET_LOCK` is re-entrant in one session; A queries, writer B's refresh starts and waits, A writes its older list and releases, B writes → B's trigger is in the option); lock timeout path (the second process holds the lock past 5 seconds) logs and deletes the option, and the next read rebuilds it; `Cleanup::run()` recomputes a wrong list; in a fresh request only active triggers have a listener (count with `has_action`); same-process activate then order → a run; `update_order_meta` on `_order_total` fails, order total unchanged, on HPOS and posts; a normal key writes, note added.
   - Docs: design §3 (Dispatcher, new "Active triggers" bullet), §5 (contract: `emits`, `warnings`); `context-dev-convention.md` line on PHP-only checks; `context-php-backend.md` if it lists scripts.
   - Done: `check-catalog`, `check-runs`, `check-orders`, `check-winback` PASS; PHPUnit; `pnpm test`.
   - Browser: step 1.

2. ✅ **CP2 List type, operators, relative dates, is_set** (5d12372) — Risk: 2
   - Files: `Core/Data/Operators.php`, `DataItem.php`, `Scope.php`, `Type.php`; `Conditions/Criteria.php`; `Core/Definition/Messages.php`, `Catalog` if the shape needs it. TS: `apps/admin-page/src/types/localize.d.ts` (`DataFieldType`), `steps/core/data.ts` (`needsValue`), `steps/conditions/criteria/schema.ts`, `index.ts`, `model/messages.ts`; fixture.
   - PHPUnit: `OperatorsTest` (each new operator; empties; `in_last` edges with a fixed now; both units); `ScopeTest` (`timestamp`, with and without the prop; a date on a site time zone); new `tests/Unit/Conditions/CriteriaTest.php` (sanitize keeps a flat rule byte-identical; array value only for list ops; `unit`; bad amounts; evaluate). `TypeTest`.
   - Vitest: `criteria/index.test.ts`, new `criteria/schema.test.ts` (same messages and order as PHP; `needsValue`).
   - check-catalog: add rule evaluation over a real order with `order.created_date` `in_last`.
   - Docs: design §4 (types), `DataItem` docblock.
   - Done: stop hook; `meta-fixture`; existing templates' tests unchanged.
   - Browser: none (form in CP5).

3. ✅ **CP3 New order / customer fields, meta conditions, customer stats (D3)** (50263b7) — Risk: 2
   - Files: `DataItems/OrderData.php`, `CustomerData.php`; new `Core/Data/CustomerStats.php`; `Core/Data/MetaKeys.php` (`user_blocked`), `DataItem.php` (`meta`, `merge_tag`), `Scope.php` (`meta`), `DataRegistry.php`, `Conditions/Criteria.php`, `Core/Definition/Validator.php`, `Messages.php`, `Core/Cart/CartCustomer.php` if a getter is missing. TS: `steps/core/variables.ts`, `steps/core/data.ts`, `steps/conditions/criteria/schema.ts`; fixture; `localize.d.ts`. No template change.
   - PHPUnit: `CustomerDataTest` (`paid_order_count` from stats, a float, label; `total_spent`; memo: 2 reads → 1 stats call; a new object → new call; order, `WC_Customer`, `CartCustomer`, guest); new `tests/Unit/DataItems/OrderDataTest.php` (each field through `FakeOrder`); `SampleValuesTest`; `DataItemExtensionTest` (`merge_tag`); `CriteriaTest` (`ruleMetaKey`, meta operators); `ValidatorTest` (`{{ order.meta }}` unknown).
   - Vitest: `variables.test.ts` (meta not offered); templates test unchanged.
   - check-catalog: stats on real data: guest + account with the same email counted once; pending and failed not counted; a refund subtracted; a second currency summed as stored; `last_order_date`; `purchased_product_ids` only queried when read (`$wpdb->num_queries`); `order.meta` / `customer.meta` read; `session_tokens` rule refused. **On HPOS and posts** (`check-winback` CP5 switch; if WooCommerce refuses, log it and add to browser steps).
   - Docs: decisions CT (stats; why the existing key stays); design §4; `ROADMAP-v1.0` Editor "Today".
   - Done: `check-catalog`, `check-lifecycle`, `check-winback` PASS; `meta-fixture`.
   - Browser: none.

4. ✅ **CP4 Search + REST search** (b6351cf) — Risk: 2
   - Files: new `Engine/API/SearchRoutes.php`; `includes/YayMatik.php`; `Core/Data/DataRegistry.php`; `DataItems/OrderData.php`, `CustomerData.php` (`search` props). TS: new `steps/core/fields/SearchSelect.tsx`, `searchSelect.query.ts`; `localize.d.ts`; fixture.
   - PHPUnit: `CatalogTest` (`search` sent; no search list in meta).
   - Vitest: new `SearchSelect.test.tsx` (paging, `include` labels, deleted id `#12`, debounce).
   - check-catalog: each route as admin (paging, `hasMore`, `include`) and as a customer (403).
   - Docs: design §5 field note (`search` isn't a Field), §4; `context-php-backend.md` REST list.
   - Done: stop hook; `fetch('/wp-json/yaymatik/v1/search/products?term=a')` shape in the script.
   - Browser: none (form in CP5).

5. ✅ **CP5 Condition form for the new rule kinds** (7f0c99c) — Risk: 2
   - **Mock section to be given. Stop and ask if missing.**
   - Files: `steps/conditions/criteria/Form.tsx`, `ValueInput.tsx`, `schema.ts`, `index.ts`; `Engine/API/SearchRoutes.php` (`customer-meta-keys`); `Conditions/Criteria.php` (messages); `Messages.php`, `model/messages.ts`; fixture.
   - PHPUnit: `CriteriaTest` (same error order as TS).
   - Vitest: new `criteria/Form.test.tsx` (each kind draws its control; field change clears a shape that no longer fits; summary text, list count, "in the last 3 days"); meta-key suggestions.
   - check-catalog: `customer-meta-keys` returns keys in rules.
   - Docs: `ROADMAP-v1.0` Editor; design §5 "rules".
   - Done: stop hook.
   - Browser: step 2.

6. ✅ **CP6 Condition groups (D10)** (a49db82) — Risk: 2
   - **Mock section to be given. Stop and ask if missing.**
   - Files: `Conditions/Criteria.php`; `Core/Steps/Field.php` (`is_blank`). TS: `steps/core/fields/catalogSchema.ts` (`isBlank`), `steps/conditions/criteria/schema.ts`, `Form.tsx`, `index.ts`; `Engine/API/SearchRoutes.php` (groups). Grep every reader of `rules` in `includes/` and `apps/`.
   - PHPUnit: `CriteriaTest` (flat old config unchanged after sanitize; group all / any, nested dropped, empty group, `uses` through groups, evaluate); `FieldTest` (only-groups isn't blank).
   - Vitest: `schema.test.ts`, `Form.test.tsx` (add group, remove, errors per rule), `catalogSchema.test.ts`, `templates.test.ts` (all 18 graphs unchanged).
   - check-catalog: a graph with a group runs end to end down each branch.
   - Docs: design §5; decisions CT; ROADMAP-dev items ✅ for run 1 stay ⏳ until merge.
   - Done: stop hook; `check-catalog`, `check-runs` PASS.
   - Browser: step 3.
   - **End of run 1: report and stop.**

### Run 2

7. ✅ **CP7 Order paid, Order note added** (8a8e68f) — Risk: 2
   - Files: new `Triggers/OrderPaidTrigger.php`, `OrderPaid.php`, `OrderNoteAdded.php`; `Modules/WoocommerceModule.php`; `Actions/ChangeOrderStatus.php`, `AddOrderNote.php`, `UpdateOrderMeta.php` (`emits`); TS `steps/triggers/order-paid/index.ts`, `steps/triggers/order-note-added/index.ts`, `steps/index.ts`, `packages/types/src/workflow.ts`; fixture; `tests/Unit/ModuleRegistryTest.php`, `Core/Steps/StepContractTest.php`, `Triggers/TriggerRefsTest.php`.
   - PHPUnit: new `tests/Unit/Triggers/OrderPaidTest.php` (transitions table; already-paid id dropped), `OrderNoteAddedTest.php` (type, contains, skip toggle, `fires_for_own_runs`).
   - Vitest: `moduleSteps.test.ts` parity.
   - check-catalog (both stores): pending → processing fires once; again after refunded → processing doesn't; `wc_create_order( status processing )` doesn't; created pending then `payment_complete()` does; auto-draft saved to processing (the admin path) does; checkout-draft → processing does; other paid-status filter value counts. Note: type, contains (case), a run's note skipped, toggle off starts (loop guard still holds), status note during a run skipped.
   - Docs: design §3 (paid event); ROADMAP-v1.0 Editor "Today".
   - Done: `check-catalog`, `check-status-flow`, `check-orders` PASS.
   - Browser: step 4.

8. ✅ **CP8 Order refunded + refund item** (95c47ac) — Risk: 2
   - Files: new `Triggers/OrderRefunded.php`, `DataItems/RefundData.php`; `Core/Data/RefResolver.php`; `Modules/WoocommerceModule.php`; TS `steps/triggers/order-refunded/index.ts`, `steps/index.ts`, `workflow.ts`; fixture; parity tests.
   - PHPUnit: `TriggerRefsTest` (refund ref; null without `refund_id`); new `tests/Unit/DataItems/RefundDataTest.php` (`is_full`: partial, partial then rest, zero total); `SampleValuesTest`.
   - Vitest: parity.
   - check-catalog: partial and full `wc_create_refund`, each one run on `order:<id>`; `is_full` stays false on the first after the second lands; `refund` tag in an email; refund deleted during a Wait → step skipped "Refund no longer exists."; Preview → sample, badge.
   - Docs: design §4 (entity list, §1 folder table).
   - Done: `check-catalog` PASS.
   - Browser: step 5.

9. ✅ **CP9 Milestone triggers + claim_key()** (d04cb7a) — Risk: 3 → `/code-review high`
   - Files: new `Triggers/CustomerOrderCountReaches.php`, `CustomerTotalSpendReaches.php` (shared code in a small abstract `Triggers/CustomerMilestone.php`); `Core/Steps/Trigger.php`; `Core/Runtime/Dispatcher.php`, `Worker.php`; `Core/Storage/TriggerClaimStore.php`; `Engine/Privacy/PersonalData.php`; `Modules/WoocommerceModule.php`; `Actions/ChangeOrderStatus.php` (`emits`); TS `steps/triggers/customer-order-count-reaches/index.ts`, `customer-total-spend-reaches/index.ts`, `steps/index.ts`, `workflow.ts`; fixture; `tests/Support/FakeRunRepository.php`.
   - PHPUnit: `DispatcherWorkerTest` (claim and release on `claim_key`; Worker release through scope `claim_key`; subject still `order:<id>`); new `tests/Unit/Triggers/CustomerMilestoneTest.php` (crossing table for count and spend, refund inside, whole-number sanitize, keys: `user:<id>`, `email:` + 64 hex, 70 characters, same hash for any case).
   - Vitest: parity; number rules.
   - check-catalog (both stores): 2nd order crosses `count 2` → a run; the 3rd doesn't; a workflow activated with history doesn't start until crossed; guest by email, other case same key; account + guest order same person; refund drops below, crossing again → no second run; two workflows → one each; turned off before the first step → released, retried; no plain email in `trigger_claims` or in the run's `claim_key`; `find_keys` with the email finds the hashed key; export lists `email:` keys, erase deletes them; `user:` keys stay.
   - Docs: design §2 (`trigger_claims` holds a key: a subject, `user:<id>` or a hashed email), §3 (claims), §11; decisions CT and C11; policy text in `PersonalData` if it must name them; ROADMAP-dev Backlog line.
   - Done: `check-catalog`, `check-winback`, `check-carts`, `check-runs` PASS.
   - Browser: step 6.

10. ✅ **CP10 Review posted + review item + Reviews category** (7b0f579) — Risk: 3 → `/code-review high`
    - **Mock section for the Reviews picker group: use it if given; else the existing section style.**
    - Files: new `Triggers/ReviewPosted.php`, `DataItems/ReviewData.php`, `Core/Data/EmailCustomer.php`; `Core/Data/RefResolver.php`; `Core/Runtime/RunPresenter.php`, `Executor.php` if `gone_message` needs it; `Engine/Privacy/PersonalData.php` (subjects); `Modules/WoocommerceModule.php`; TS `steps/triggers/review-posted/index.ts`, `steps/index.ts`, `pages/editor/StepPicker.tsx`, `workflow.ts`, `localize.d.ts`; fixture; parity tests; `tests/Unit/DataItems/CustomerDataTest.php`.
    - PHPUnit: new `tests/Unit/Triggers/ReviewPostedTest.php` (type, parent, status filters); new `tests/Unit/DataItems/ReviewDataTest.php`; `CustomerDataTest` (`EmailCustomer`); RunPresenter test if one exists.
    - Vitest: parity; picker test for the heading.
    - check-catalog: held then approved → one run, rating and verified read at shutdown; spam or held → none; unapprove then approve → no second run; reply → none; a plain post comment → none; admin-added review; guest email only even when an account has that email (`customer.first_name` empty, `customer.role` empty); account by `user_id`; comment deleted → skipped; export and erase find `review:` runs; Preview → sample.
    - Docs: design §4 (`review:` customer), §5 picker sections, §1, §2 subject list; ROADMAP-v1.0 Editor.
    - Done: `check-catalog`, `check-lifecycle` PASS.
    - Browser: step 7.
    - **End of run 2: report and stop.**

### Run 3

11. ✅ **CP11 Update customer custom field** (3fa3e41) — Risk: 3 → `/code-review high`
    - Files: new `Actions/UpdateCustomerMeta.php`; `Core/Data/MetaKeys.php` (`user_collides`); `Core/Steps/Action.php` (`CATEGORY_CUSTOMER`), `Field.php` (`suggest`); `Engine/API/SearchRoutes.php` (step keys); `Modules/WoocommerceModule.php`. TS: `steps/actions/update-customer-meta/index.ts`, `steps/index.ts`, `steps/core/fields/widgets.tsx`, `StepForm.tsx`, `pages/editor/StepPicker.tsx`, `localize.d.ts`, `workflow.ts`; fixture; parity tests.
    - PHPUnit: `StepRunTest`, `StepConfigsTest`, `FieldTest` (`suggest`); new `tests/Unit/Core/Data/MetaKeysTest.php` (every blocked pattern, `wp_2_capabilities`, case, base prefix quoted).
    - Vitest: `catalogSchema.test.ts`; panel lists the suggestions under the input.
    - check-catalog: write and `old` → `new`; guest skipped; each blocked key refused at save and at run (a stored config bypassing the panel); `WP_CAPABILITIES` refused by the collision check; capabilities unchanged afterwards; user 0.
    - Docs: design §5; decisions CT (why the collision check); ROADMAP-v1.0 Editor.
    - Done: `check-catalog` PASS.
    - Browser: step 8.

12. ✅ **CP12 Change customer role** (dabffe2) — Risk: 3 → `/code-review high`
    - Files: new `Actions/ChangeCustomerRole.php`; `Modules/WoocommerceModule.php`; TS `steps/actions/change-customer-role/index.ts`, `steps/index.ts`, `workflow.ts`; fixture; parity tests.
    - PHPUnit: `StepConfigsTest` (`assignable_roles`, the filter); new `tests/Unit/Actions/ChangeCustomerRoleTest.php` where WP can be faked; the rest in the script.
    - Vitest: parity; options list has no protected role.
    - check-catalog: add, remove, replace; last role kept; a holder of each role family (admin, shop manager, a custom role with `edit_users`, a user with a direct cap) untouched; a role made protected after save → failed at run; filter on → allowed, last-role still kept; guest skipped; user 0; a multiple-role user keeps protected-free roles on add.
    - Docs: decisions CT; ROADMAP-v1.0 Editor.
    - Done: `check-catalog` PASS.
    - Browser: step 9.

13. ✅ **CP13 Resend WooCommerce email** (5796f31) — Risk: 2
    - Files: new `Actions/ResendWcEmail.php`; `Modules/WoocommerceModule.php`; TS `steps/actions/resend-wc-email/index.ts`, `steps/index.ts`, `workflow.ts`; fixture; parity tests.
    - PHPUnit: `StepConfigsTest`, `StepRunTest` with `tests/Support/fake-wc.php` (allowlist; filter `yaymatik_resendable_emails` adds and removes ids; an id not in `get_emails()` dropped; an admin email dropped by `is_customer_email()`; refunded absent).
    - Vitest: parity.
    - check-catalog: options list = the 7 allowlisted ids present on this WooCommerce, none other; each listed one sends through the mail catcher with the order's billing email; turned off → skipped; no billing email → skipped.
    - Docs: known limit (refunded) in decisions CT; ROADMAP-v1.0 Customer emails or Editor.
    - Done: `check-catalog` PASS.
    - Browser: step 10.

14. ✅ **CP14 Wait until** (dc00f64) — Risk: 2
    - Files: new `Actions/WaitUntil.php`; `Core/Steps/StepResult.php`; `Modules/YayMatikModule.php` (and its description); TS `steps/actions/wait-until/index.ts`, `steps/index.ts`, `workflow.ts`; fixture; parity tests.
    - PHPUnit: new `tests/Unit/Actions/WaitUntilTest.php` (`next_time`: later today, passed today, chosen weekday, week wrap, empty days, hour 0, a spring-forward and a fall-back date in a DST zone, a negative-offset zone); `StepResultTest` (outputs on wait); `DispatcherWorkerTest` (output survives the wake).
    - Vitest: parity; hour `0` doesn't read as blank.
    - check-catalog: run waits to the right UTC, resumes, later step reads `steps.x.until`; Deactivate cancels it; site time zone respected.
    - Docs: design §1, §3 (Wait until beside Wait); ROADMAP-v1.0 Editor.
    - Done: `check-catalog`, `check-runs` PASS.
    - Browser: step 11.

15. ✅ **CP15 Stop waiting runs + shared subjects helper** (b0f34a3) — Risk: 2
    - Files: new `Actions/StopWaitingRuns.php`, `Core/Runtime/PersonSubjects.php`; `Core/Runtime/Cancellation.php`; `Core/Storage/RunStore.php`, `WorkflowStore.php` (`names`); `Engine/Privacy/PersonalData.php`; `Modules/YayMatikModule.php`; TS `steps/actions/stop-waiting-runs/index.ts`, `steps/index.ts`, `workflow.ts`; fixture; parity tests.
    - PHPUnit: `StepConfigsTest` (deleted id → issue; required); `StepRunTest`; `on_error` continue in `DispatcherWorkerTest`.
    - Vitest: parity; multiselect required.
    - check-catalog: order, cart, account and review subjects all stopped; only chosen workflows; other people's runs stay; reason text; steps closed `cancelled`; invalid email skipped; deleted target → step failed, run continues; export and erase behave as before the move (`check-carts` privacy block).
    - Docs: design §3 (Cancel), decisions CT; ROADMAP-v1.0 Editor.
    - Done: `check-catalog`, `check-carts`, `check-lifecycle` PASS.
    - Browser: step 12.

16. ✅ **CP16 check-catalog finish, docs review, PR text** (9a42e75) — Risk: 2
    - Files: `dev/scripts/check-catalog.php` (a custom workflow per new trigger, end to end, with an email through the mail catcher and a group condition; a run per action); docs; `.claude/plans/dev/030-pr.md`.
    - Run: `./run.sh check-catalog`, `check-winback`, `check-lifecycle`, `check-carts`, `check-runs`, `check-orders`, `check-status-flow`, `check-storage`, `check-late-module`; PHPUnit, phpcs, `pnpm build`, `pnpm lint`, `pnpm test`.
    - Both stores: note `woocommerce_custom_orders_table_enabled`, run `check-catalog` with `yes` and `no`, restore it. If WooCommerce refuses (sync pending), log it under Deviations and add to Jack's browser steps; no sync workaround.
    - Docs: decisions CT cost lines = Known limits; design §1, §2, §3, §4, §5, §11 read through; ROADMAP-dev CT ⏳; ROADMAP-v1.0 Editor, Template Library, Customer emails "Today".
    - Docs grep over `docs/` and `.claude/docs/`: `PAID_STATUSES`, `customer.order_count`, `KEY_MAX` none; `is_internal_meta_key` none; `search_select` none; `030` no M9 hit.
    - Symbol search: `MetaKeys` (`order_blocked`, `user_blocked`, `user_collides`), `active_trigger_ids`, `refresh_active_triggers`, the lock name `{prefix}yaymatik_active_triggers`, `listen_active`, `yaymatik_active_triggers`, `yaymatik_active_triggers_changed`, `emits`, `fires_for_own_runs`, `warnings`, `may_refire`, `editedWarnings`, `CustomerStats` (`totals`, `product_ids`), `user_id`, `TYPE_LIST`, `TYPE_META`, `timestamp`, `merge_tag`, `search`, `suggest`, `SearchRoutes`, `SearchSelect`, `OrderPaidTrigger`, `claim_key`, `email_key`, `find_keys`, `delete_keys`, `EmailCustomer`, `RefundData`, `ReviewData`, `CATEGORY_CUSTOMER`, `assignable_roles`, `PROTECTED_CAPS`, `CORE_EMAILS`, `yaymatik_resendable_emails`, `next_time`, `names`, `PersonSubjects`, `for_subjects`, every new step class and TS step export.
    - Browser: run through all steps below once more, light and dark.
    - **End of run 3: report, PR text and stop.**

## Out of scope
HTTP / webhook, Slack, tags, stock trigger, product actions, refund action, Change workflow status, YayMatik triggers. Regex comparisons. A Run limit per workflow, customer tags, stock triggers, Workflow run failed, random split, multi-currency totals (v1.1 backlog, CP0). New templates. A "Test run". Resending refunded emails. Condition groups deeper than one level.

## Browser steps for Jack
R = `SELECT id,subject,status,error FROM wp_yaymatik_runs ORDER BY id DESC LIMIT 10;` Compare with the mock frames, light and dark, when given.

1. CP1: Order status changed + Change order status → an amber line in that step's panel; Activate still works. Update order custom field with `_order_total` → an issue after save; a normal key → order note with old and new.
2. CP5: build one rule of each kind: list (options and search), `is_any_of`, `in_last` with both units, `is_set`, meta. Search pages, reload keeps labels, canvas summary reads well.
3. CP6: a group inside a condition, any inside all; the summary; delete the only group; an existing template's condition is unchanged.
4. CP7: Order paid: checkout with a pending gateway, then mark processing → one run; to completed → none. Admin creates an order straight to Processing → one run. Order note added: add a private note from the order screen → run; Add order note step in another workflow → no run while Skip is on.
5. CP8: partial refund from the order screen → a run, `refund.amount` in the email, Full refund No; refund the rest → second run, Yes.
6. CP9: a customer with 1 paid order, threshold 2: pay a second → run; a third → none. A guest by email, same. Run label "Order #N".
7. CP10: picker shows Reviews. Post a review logged out with moderation on → no run; approve → run, Run label "Review #N" linked; approve again after unapproving → none. Email shows rating and product name.
8. CP11: key suggestions show keys from other workflows; `wp_capabilities` → an issue; a run writes the key on a customer, run detail shows old and new.
9. CP12: role list has no Administrator or Shop manager; replace on a customer works; run on a shop manager → skipped with the reason.
10. CP13: list shows only customer order emails; resend Completed order → arrives; turn it off in WooCommerce → skipped with the reason.
11. CP14: hour next hour, today's weekday → Waiting, Run detail shows the time in the store's zone; Wait until runs on.
12. CP15: a waiting cart run and a waiting order run for one email; Stop waiting runs in a third workflow → both Cancelled with "Stopped by workflow “X”."; delete a chosen workflow → step issue, and a run shows the failed step and continues.
13. **Posts store (if not run in CP16):** WooCommerce → Settings → Advanced → Features: sync orders until none pending, switch to posts, `./run.sh check-catalog`, then switch back.

## Deviations
- After run 3 (Jack, to match the mock #ct-cp5, #ct-pickers, #ct-triggers, #ct-actions):
  - Trigger icons as the mock: Order paid `circle-check`, Order refunded `refresh-cw`, Order note added `message-square`, Customer order count reaches `activity`, Customer total spend reaches `heart-pulse`; `circle-check`, `activity`, `heart-pulse` added to `StepIcons::NAMES` and the TS map. The parity test checks the five.
  - Custom field key suggestions (Condition customer custom field rule, Update customer custom field) are `KeySuggestInput`: a kit Popover + Command list under the input, heading "Used in your workflows", narrowed as you type. Nothing is highlighted until an arrow key moves into the list, so Enter keeps the typed name; Escape closes. The order custom field rule has no suggestions (the route lists customer keys only).
  - Search fields type inside the field beside the chips, the list below, as "Search open". The kit has no combobox with an inline search input (its `combobox.tsx` has trigger, badge and checkbox parts, and `CommandInput` wraps the input with an icon and a border), so `SearchSelect` is built from kit parts in the kit's `TagsInput` layout: `InputGroup` + `ComboboxBadge` chips + `InputGroupInput`, anchored with `PopoverAnchor`, the `Command` list in the popover. Arrow keys and Enter pick, Backspace on an empty input removes the last chip, Escape closes.
  - A condition's PHP rule issue carries `rule` (`[item]` or `[group, rule]`; `Step::invalid()` takes extra data, the Validator copies it to the issue, `WorkflowIssue.rule`). The panel passes such an issue to the form, which draws it under that rule unless the form has its own error there; an issue with no place stays at the top. An empty group's issue names the group.
- CP11: `MetaKeys::user_collides( int $user_id, string $key )` reads only this user's rows, with no DISTINCT (review: DISTINCT folds the collation's spellings into one arbitrary key, which could be a harmless one). Those are the rows `update_user_meta()` can reach. A failed read counts as a collision.
- CP11: `suggest` draws its `<datalist>` through a `CustomerMetaKeys` child, so only a field that suggests mounts the query (other text fields need no QueryClient). Condition rules' key input reuses it. A save marks the suggestions stale (`refetchType: 'none'`), so the next panel opened reads them again and an autosave doesn't refetch.
- CP11: `Hints` (in `triggers/core/NoSettings.tsx`) also serves action panels. The action picker's section reads "Customers", like the trigger picker (shared label), not the mock's "Customer".
- CP11 review: a deleted account is skipped like a guest; a write that `update_user_meta()` refuses fails "Couldn’t save the custom field."
- CP11 review, not changed: a key that differs from a stored one only in case reads as changed each run (the "unchanged" check is exact, the write uses the collation); save time checks `user_blocked()` only, since the collision check needs an account.
- CP11: the v1.1 backlog line for an Order note added `note` data item (Jack, run 3) went into this commit's ROADMAP-dev change.
- CP12: the role field's summary reads "%mode%: %role%" ("Add a role: Wholesale customer"); outputs `from` / `to` are role names (labels). Run detail: "Added X", "Removed X", "Replaced roles with X", "Roles unchanged". The fixture keeps only the core roles in the role list. `assignable_roles()` lists `was_staff`-style roles whose protected caps are set false.
- CP12 review: a user counts as protected when `user_can()` grants a protected cap (so a `user_has_cap` filter counts), not only by `allcaps`. Remove of a role that is gone or now protected is a no-op, not a failure: this user can't hold it.
- CP12 (Jack, after run 3): `PROTECTED_CAPS` adds `edit_posts`, `publish_posts`, `edit_others_posts`, `edit_pages`, `upload_files`, `moderate_comments`, `edit_products`, `edit_shop_coupons`, `manage_product_terms`, `view_woocommerce_reports`, `import`, `export`: triggers fire on public events, so any buyer would get the role, and customer roles only need `read`. Author, Editor and Contributor are no longer assignable. The role field's help, mock wording, became "Only roles with customer rights are listed: no staff, editing or publishing roles.", as the old text no longer said what's left out. The fixture's role list is now Subscriber and Customer.
- CP12 review, not changed: Outputs stay names (CP1 decision), not slugs. With the filter on, "Replaced roles with X" doesn't say which protected roles stayed, and the hint "Store staff are never changed" doesn't mention the filter.
- CP13: run detail reads "Sent Completed order", not the mock's "… to jane@example.com": runs keep refs only, and the address is the customer's. `customer_review_request` sits behind a WooCommerce feature flag and isn't registered on this site, so the list here has 6 emails. Tests in a new `tests/Unit/Actions/ResendWcEmailTest.php` with `FakeWcEmail`, not in StepConfigsTest / StepRunTest.
- CP14: icon `history` (the mock's) added to `StepIcons` and the TS map. Hours are '0'–'23' strings (a select), default '9'; the card reads "Until %hour% · %weekdays%", with three or more days in a row as a range ("Mon–Fri") and "Any day" when none. The panel hint names the store's zone (`timezone_string`, else its UTC offset) and the chosen hour. Run detail: "until …" while waiting, "Waited until …" with the time it resumed.
- CP14: `next_time()` builds each day's time from its date string, not `setTime()`: `setTime()` keeps that day's offset, so a repeated fall-back hour read as the second one (the plan wants the first).
- CP14: `DispatcherWorkerTest` gets no wait-output case: check-catalog runs the real Worker, and a later step reads `steps.until.until` after the wake. "Deactivate cancels it" is checked on the wake path (a waiting run of an inactive workflow is cancelled when it wakes); `WorkflowStore::deactivate()` alone doesn't cancel, the REST Deactivate does. check-catalog switches the site's `timezone_string` to Asia/Ho_Chi_Minh for this block, then puts it back.
- CP15: a chosen workflow deleted since gets the mock's own message ("A chosen workflow was deleted. Remove it from the list.") from `validate()`, before the field's generic one; a deleted id's chip reads its bare id, not the mock's "#14" (`MultiSelect` shows any unknown value as itself). Icon `power` (the mock's) added. New `Type::number()`. Run detail: "Stopped N runs".
- CP15: the workflow list is sent with the catalog, so a workflow created after the page loaded shows only after a reload. In the v1.1 backlog (Jack).
- CP15: tests' bootstrap sets an empty `FakeWpdb` as `$wpdb`, since building the catalog now reads the workflow list; tests that reset `$wpdb` to null put that one back, and StepConfigs / StepContract tests dropped their own `$wpdb`. Unit tests in a new `tests/Unit/Actions/StopWaitingRunsTest.php`; no `DispatcherWorkerTest` case (on_error continue is the Executor's existing path; check-catalog runs a deleted target through the real Worker). The parity test fills a required multiselect with a list.
- CP16: the end-to-end block is its own file, `dev/scripts/check-catalog-e2e.php` (check-catalog.php passes 950 lines). It runs one workflow per new trigger (group condition, then Send email to the customer, caught) and one run through all five new actions.
- CP16: both stores without switching the site setting (`woocommerce_custom_orders_table_enabled` stays `yes`): check-catalog already runs a second process on the posts store (CP1 deviation). So browser step 13 isn't needed.
- CP16: two flaky checks fixed. Review mails (CP10): `$started` now unschedules each new run's job at once (641927b). Category search with "&" (CP4): it searched "Men & Boys", and 35 categories left by earlier aborted runs pushed the new one off page 1; it now searches with this run's word. **For Jack:** those 35 leftover `chk… Men &amp; Boys` product categories are still on the dev site; not deleted.
- CP16: design §5 said an empty group is `rulesRequired`; corrected to `groupRulesRequired` (6025f7e changed the code). Decisions CT gains its **Cost.** block (the known limits).
- Run 3: check-catalog is run with the site's own active workflow #34 (High-value order alert, no waiting runs) deactivated, then activated again after each run.
- Run 3: CP10's review mail checks failed once on the posts store and passed the next five runs: a race, most likely the site's Action Scheduler runner taking the queued runs before `$work` does. Not changed.
- CP10 (mock `#ct-pickers`, `#ct-triggers`): the Reviews picker section and the Review posted panel use the mock's words; panel hints via `NoSettings`. `ReviewData::is_review()` / `find()` hold the review check, so `RefResolver` doesn't import a trigger. New `StepPicker.test.tsx` (no picker test existed).
- CP10 review: a review without stars has no rating (null), and a number rule never matches a value that isn't a number (`Operators::compare`), so "Rating < 3" skips it. Review posted starts only on unapproved → approved (and approved at insert); restoring from trash or spam doesn't start it. YayMatik's eraser now runs first: WordPress's comments eraser blanks the review email and user id that the review lookup needs. Review subjects include spam and trash, and reviews by every account found for the email.
- CP10 review, not changed: a guest reviewer's stats (paid orders, total spent) are read by the typed email, as for any guest (D3); N+1 `get_comment` in the runs list.
- CP10: the check's account reviewer gets this site's default role (Subscriber), so the check reads "a role", not "customer".
- CP9: `fires_for_own_runs()` false moved to `OrderPaidTrigger`, so Order paid and both milestones share it (their runs act on an order or person already claimed). Total spend reaches has no default amount: required, so a new trigger shows Needs setup. Descriptions follow the picker mock without a final period, like Customer inactive. Hints under the fields come from a new `Hints` in `triggers/core/NoSettings.tsx`. Privacy policy text gains a line on the hashed claim.
- CP9: "turned off before the first step → retried": the claim is released (checked), but a milestone has no retry, as the next order no longer crosses the number.
- CP9 review: an erase now finds a guest's milestone runs by the email's claim key (`RunStore::about()` also matches `scope` LIKE the key) and replaces the key with `[deleted]`; before, completed milestone runs kept the hash. A failed claim delete in an erase is reported (`delete_keys()` returns false; "Some customer milestone records could not be erased."). Known limit added: an order saved already paid counts in the totals but starts nothing.
- CP9 review, not changed: totals read at shutdown (item 19, now noting Action Scheduler batches), `claim_key` in the trigger scope (item 12), unsalted sha256 (item 13), stats read twice when both milestones are active.
- CP9: `check-winback` fails one check, "cleanup: claims of a deleted workflow gone", on this dev site only: its `trigger_claims` table has no `id` column (see CP7). Not a code fault; needs the dev table rebuilt.
- CP8: the refund tags are checked through an Add order note step (same Template as an email); CP16 sends the real email. `RefundData::is_full` is private and tested through its field. Refund is a data item after customer (`site, order, cart, customer, refund`).
- CP8 review: Change order status also emits Order refunded (moving an order to Refunded makes WooCommerce refund the rest). Known limits added to design §4: `is_full` reads amounts only (a free item not yet refunded keeps WooCommerce at "partial"), and deleting an earlier refund before a Wait ends changes a later one's answer.
- CP8 review, not changed: Preview of an Order refunded email on a real order shows sample values for everything (refs need a refund id), as research item 22 decided.
- CP7 (corrects research item 1): WooCommerce also fires `woocommerce_new_order` when a draft is placed (`OrdersTableDataStore.php:3127`, `class-wc-order-data-store-cpt.php:240`), so remembering ids there dropped admin and checkout-draft orders. Orders saved already paid are caught at `woocommerce_before_order_object_save` while they have no id, given their id at `woocommerce_new_order`, and released at `woocommerce_after_order_object_save`.
- CP7 review: `OrderPaid` marks an order saved already paid with `_yaymatik_order_paid` at creation, so a later unpaid → paid change never starts it (the mock says imported paid orders don't start it). `OrderPaid::fires_for_own_runs()` is false: its runs act on the order that started them, so Change order status in an Order paid workflow raises no false warning. `contains` is compared with entities decoded (saving turns a lone `<` into `&lt;`).
- CP7 review, not changed: the Order paid hint names Processing or Completed though a filter can change the paid list (mock wording); `by_run` stays in the event args (item 17).
- CP7: the check-catalog trigger checks live in `dev/scripts/check-catalog-triggers.php`, required by `check-catalog.php` (953 lines already). New `triggers/core/NoSettings.tsx` draws "No settings needed." plus hints for Order paid; CP8 and CP10 reuse it. Order note added summary: "%note_type% added to an order".
- CP7: this dev site's `trigger_claims` table predates its `id` column (DB version already 7), so `Cleanup::run()` logs `Unknown column 'c.id'` in check-catalog. Earlier dev data; not changed. check-status-flow's default orders are gone; run with two real ids (906725 906715 used).
- CP6: Add group replaces the lone blank rule a new condition starts with, or it would block Activate unseen. A new group starts with one blank rule, with no picker opened. A group's summary collapses past 3 rules like the condition's.
- CP6 (Jack, run 2): an empty group gets its own key `groupRulesRequired`, "Add a rule, or remove this group.", in `Criteria::validate` and `criteriaErrors`, not `rulesRequired` as planned (6025f7e).
- Run 2: `SearchSelect.test.tsx` was flaky under the full suite (read the list one tick after opening); it now waits for its requests to answer (d8f2391). `meta-fixture` output keeps HEAD's site-specific option lists (roles, gateways) so dev-site plugins don't churn the fixture.
- CP6: `check-catalog` runs its group workflow through the real Worker on both stores.
- CP5 (mock wording, Jack's instruction): `ruleAmount`, `ruleKeyRequired`, `ruleMetaKey` and the field labels take the mock's words.
- CP5 (as built in run 1, changed after run 3 by Jack, see the run 3 fix below): key suggestions were a `<datalist>`, search typed in the popover's own input, and `ruleMetaKey` showed at the top of the panel.
- CP5: search items gain `hint` (`#118` or a status), as the mock draws. New `customer-meta-keys` returns `{ keys }`, live and draft graphs.
- CP5: a card shows at most 3 rules, then the first and "and N more", from the mock's frames.
- CP1 (Jack's decision): Update order custom field outputs `key` + `changed`, not `old` / `new`: `check-runs` keeps its rule that rendered values never reach runs or run_steps, so the plan's Safety, CP1 and CP11 lines changed. Old → new stays in the private order note, added only when the value changed. CP11 the same, with no note. Change customer role may output role names.
- CP1: readers store the list with `INSERT IGNORE`, not `add_option()`. After a missed read `add_option()` skips its check and runs `ON DUPLICATE KEY UPDATE` (`wp-includes/option.php:1119–1142`), so a stale reader would overwrite a fresh list. Keeps item 16's intent.
- CP1: writers clear the cached option before `update_option()`, which skips the write when the value equals this request's cached copy, even if another request changed the row since.
- CP1: `Dispatcher` constructor gains `$triggers` (`Runtime` passes `StepRegistry::triggers`), like the Worker's step lookup.
- CP4: `SearchSelect` has no caller until CP5 draws it (`ValueInput`); a checkpoint of this plan, not dead code. CP5: give it `key={type}`.
- CP4 review: labels are decoded and categories read "Men › Shoes" (two can share a name). Search adds `ID` to its sort so paging can't repeat or skip a row. Labels batch `include[]` at the route's 100. A failed request never shows a value as deleted or the list as empty.
- CP3 review: `CustomerStats::totals()` rounds `spent` to 8 places (float drift missed an exact rule). Known limit documented: stats memo per customer object also holds within one slice.
- CP3: `order.created_date` and the new dates use real times; `paid_ids()` lost its `$limit`, since only `is_first_order` calls it now. `DataItem::text()` replaces `OrderData::text()`.
- CP3: `ruleKeyRequired` and `ruleMetaKey` come here, not CP5. TS checks the key is there; only PHP knows which keys are blocked (`meta_blocked` field prop), so `ruleMetaKey` shows after a save.
- CP3: `total_spent`'s help text waits for CP5: nothing draws a data field's help yet.
- **For CP5:** `merge_tag` false also hides `order.meta` / `customer.meta` from the rule picker, which reads `scope.variables`. The picker must add them for condition rules.
- CP2: `ruleAmount` and `ruleListRequired` come here, not CP5: the rule checks and their tests need them.
- CP2 review: `order.created_date` and `cart.last_activity` get `timestamp`, or "in the last 3 hours" reads 00:00 on those fields. `chooseField` keeps `unit` when the value still fits; a summary shows no amount until it is a whole number.
- CP2: until CP5, the form offers the new comparisons but draws the old inputs.
- CP1 review: `wp_*` order keys are blocked too: WooCommerce hides them on read, so `changed` would always be true and rows would pile up. The same value on an existing key doesn't save the order or add a note.
- CP1: `check-runs`, `check-orders`, `check-status-flow` wrote `_yaymatik_*` keys through the step, now refused; renamed to `_check_*`.
- CP1: new `dev/scripts/check-catalog-posts.php`: `wp --require` puts one process on the posts store without changing the site setting or needing sync, so `check-catalog` covers both stores.

## Meta
- Target branch: jack/feature/030-catalog
- Base branch: develop (f747ff8)
