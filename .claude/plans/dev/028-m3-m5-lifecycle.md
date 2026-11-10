# Plan: 028 — M3 Review request, M4 Welcome series, M5 High-value order alert

## Goal
A completed order can ask for a review of each product not yet reviewed, a new account gets a welcome series, the high-value alert fires when a large order is paid, and the library gains 4 + 1 templates.

## Rules for /wdev (from Jack's brief and CLAUDE.local.md)
- One-pass mode: all CPs in one run. After each CP: stop hook green, `/code-review high` on risk 2–3 CPs (fix findings, skip ones about earlier dev data), commit locally by path, mark ✅ + hash here ("✅ **CP2 …** (a1b2c3d)"), go on without waiting.
- Stop and ask only when: tests or lint still fail after two fix attempts; the code contradicts a decision below; an irreversible step is needed (deleting data, `git reset --hard`, a schema change outside this plan); two valid options and the plan doesn't choose. Small choices: take the simplest, log under "Deviations".
- Commits: stage by path (never `git add -A` / `.`); never `.claude/settings*.json`; no Co-Authored-By / Claude-Session lines; message `feat|fix|docs: plan 028 CPn — …`. Review fixes are new `fix:` commits, never amend or rebase. Never `git stash`.
- Never push or `gh pr create`. Plans stay uncommitted.
- Search every symbol added or removed; delete those without a caller (a test alone is not a caller). `composer dump-autoload` after a new PHP class. `./run.sh meta-fixture` after a catalog or `yayMatikMeta` change; `localize.d.ts` with it.
- Docs grep with an allowed-hits list; never reword decision lines to pass it. A difference from the docs updates the docs in the same commit.
- No other product's name in code or docs. Dates and schedules never in `docs/`.
- UI: build to YayMatik Pages v3 (https://claude.ai/artifact/R6XzCEZqqyVHBe7uaknMoS) #m3m5 and #picker (Customers section), #triggers (`tAccount`). Overrides are listed below. YayUiKits only; theme tokens, no `dark:`; amber notes reuse `CartTrackingWarning`'s tokens (`bg-warning-soft`, `text-foreground`); badges reuse `WarningBadge`. Authority: this plan, docs, then the mock.
- End of the PR: one report and stop. Per CP: hash, files, tests, review findings and fixes, deviations. Then: unused code outside the task, browser steps for Jack (no Chrome here), PR text in `.claude/plans/dev/028-pr.md`.

## What research changed (read this first)
Each item adjusts the brief. Say so in the report.

1. **W1's "admin-created / imported users don't start it" needs a guard.** The brief lists four callers of `wc_create_new_customer()`. There is a fifth: `WC_Customer_Data_Store::create()` (`class-wc-customer-data-store.php:114`), so any `new WC_Customer; … save()` fires `woocommerce_created_customer` too. That includes REST `POST /wc/*/customers` (`class-wc-rest-customers-v1-controller.php:458`, inherited by v2 / v3) and importers that save customers. An ERP sync would mail its whole list. **Fix:** the trigger also listens to `woocommerce_new_customer`, which only the data store fires, right after the created hook. It remembers those ids, and `claim()` refuses them at shutdown. The four shopper callers go straight to `wc_create_new_customer()` and never fire it. WordPress Users → Add New uses `wp_insert_user()` and fires neither.
2. **W1 "provides customer and site": `provides()` is `[ customer ]` only.** `site` is always available (`DataRegistry::ALWAYS`, `Scope` reads it without a ref; `validation.test.ts:149`). Listing it would break Preview: `SampleValues` would take `site.name` from `SiteData::sample()`, which is empty, so the site name disappears in every sample preview.
3. **W5 "samples": no `SiteData::sample()`.** `SampleValues` never reads it for the reason above (`SampleValuesTest::test_step_outputs_and_the_site_come_from_the_scope`). Preview reads `site.*` live, which is what we want: the URLs are public and `ProductGrid::newest()` is what the run sends. A `sample()` would have no caller. Order fields (R1) do get samples.
4. **W-2 and W-3 need single buttons, and W5 has no block for them.** W-2 ends with a shop button and W-3 with an account button, but `site.welcome_links` draws both. A `<a>` typed in the message can't follow the store's email colour. **Fix:** two more blocks, `site.shop_button` and `site.account_button`, on the same helper as `welcome_links`. They are also useful in the editor.
5. **W4 must also find users by `billing_email`.** `customer.email` reads the billing email first and W2 only fills it when empty. A customer who changed billing email has a waiting welcome run that goes to an address `get_user_by( 'email' )` can't find, so an erase request for that address would leave the run waiting and it would still send. `subjects()` looks up both the account email and the `billing_email` meta.
6. **Three email blocks need one button.** `CartItems::button()` holds the bulletproof table; review rows and site blocks need it too. New `Core/Email/EmailButton`; `CartItems::button()` calls it. The ROADMAP-dev follow-up on the repeated colour lookup is updated, not closed (`GenerateCoupon`, `UnsubscribePage` keep theirs).
7. **`get_comments()` traps.** An empty `post__in` or `author_email` is ignored and `user_id => 0` matches every guest review, so either query could mark everything reviewed. Return early on no products; skip the email query on an empty email and the user query on customer id 0.
8. **W3: `gone_message()` is the one place.** `Worker::start` (run ends skipped) and `Executor` (step skipped) both call it. A `customer` ref whose id starts with `user:` says "The customer was deleted."; order and cart customers keep "Customer no longer exists."
9. **W3 URL: `admin_url( 'user-edit.php?user_id=N' )`, not `get_edit_user_link()`,** which returns `''` when the current user can't edit that user and would give the TS link an empty `href`. `user-edit.php` checks access itself, like `get_edit_order_url()`.
10. **Parity forces parts of CP5 into CP3.** The parity test needs the TS step once the PHP trigger exists, and `Record<NonNullable<Step['category']>, string>` needs the `customer` label once the type has it (same as 027 CP2). CP3 adds `steps/triggers/customer-account-created/index.ts`, `StepId`, the type and the label. CP5 adds the Footer and checks the section.
11. **`cartFlow.ts` goes general.** Welcome uses its line graph and email helpers, and review and cross-sell use the email helper. Rename to `templates/core/flow.ts`, `cartGraph` → `lineGraph( trigger: StepId, steps )`, `cartEmail` → `customerEmail`; move `rule` there from `cross-sell-upsell`. Cross-sell drops its own `email()` for `customerEmail`, which gives it the `default: "there"` greeting. Its sign-off text is already the same.
12. **`wc.reviewsEnabled` and staleness (027 finding 7).** The option changes in WooCommerce's settings, in another tab, so `yayMatikMeta` stays stale until reload. `useSaveSettings` can't help. Read it per call, not at module level; the note goes away on reload. Known limit.
13. **R3 needs no Validator change (027 finding 6).** It doesn't block Activate, so no `setup_issue()`.
14. **Check script grows per CP,** as in 027 finding 14, so risk 2–3 CPs have a real done-check. CP2 creates `check-lifecycle`; CP6 adds the H1 paths and runs everything.

Review items (Jack, plan review):
15. **W3 label = the recipient.** The `user:<id>` label is the email `customer.email` reads (`RefResolver::account( $id )->get_billing_email()`), not `user_email`, so it matches the recipient and W4's lookup. Gone → "Customer #id" unchanged.
16. **CP5 regression guard.** The `flow.ts` refactor touches every cart and cross-sell template, so CP5 dumps the existing graphs before it and diffs them after. Only the cross-sell greeting may change.
17. **The guard is per id.** CP3's check-lifecycle creates a shopper and a saved `WC_Customer` in one request and expects exactly one run, the shopper's.
18. **Browser step 7 uses wp-cli.** `wp wc customer create` goes through REST → data store, with no auth setup.

## Decisions against docs and code
Follows / silent / differs. Differences update docs in the CP named.

| # | Result | Note |
|---|---|---|
| H1 High-value trigger, ≥ 500 | Follows M5.1, Templates table. Differs: ROADMAP-v1.0 Template details "over 500" | CP1 |
| H1 re-alert limit, v1.1 guard | Silent; merges M2 follow-up "A workflow runs again…" into v1.1 Backlog | CP1 |
| R1 data + block | Follows Customer emails table. Differs: M3.1 key `products_not_reviewed` | CP2 |
| R2 known limits | Silent | CP6 |
| R3 Before you start | Follows Template preview, mock | CP5 |
| R4 graphs | Follows Template details (7, 14 days) and design §7 (branches never merge). Differs from mock's What it does order (Wait first) | Mock note |
| W1 trigger | Follows M4.1, Editor "Customer account created: no settings". Finding 1, 2 | CP3 |
| W2 billing fill | Silent; same problem as 027 finding 2 | Design §4, CP3 |
| W3 presenter | Follows mock #m3m5 runs table, M2.8 "customer links with M4". Findings 8, 9, 15 | CP3 |
| W4 privacy | Follows M7 decision (erase cancels by subject). Finding 5 | CP3 |
| W5 site fields + blocks | Differs: M4.2 "welcome email block". Findings 3, 4 | CP4 |
| W6 templates | Follows Template details (0, 3, 7 days); `welcome` exists in `TemplateWorkflow` and `workflowRank` | CP5 |
| Small: cross-sell greeting | Follows | CP5 |

## Mock overrides
Note for Jack to update the page (the mock says a decision that differs is written there the same day).
- #m3m5 alert panel: From is Pending, On hold, Failed (H1), not "Any status". To is Processing, Completed. Condition reads the existing operator label "is greater than or equal to".
- #m3m5 review preview, What it does: comes from the step cards, in R4 order (Condition before Wait).
- #m3m5 condition hint "Checked when the step runs…" and alert hint "Processing: the order is paid…": not built. Data item fields and multiselect options have no per-value hint, and neither is in the brief. Same call as the Cart field descriptions in 027.
- #m3m5 W-3 is "text only" in the mock's note; the brief adds an account button.
- Keys: `order.has_products_not_reviewed`, `order.review_products`, `site.account_url`, `site.shop_url`, `site.welcome_links`, `site.shop_button`, `site.account_button`, `site.new_products`. Order number in R-1 has no `#`.
- Descriptions have no final period, like the built Cart triggers.

## Code facts
- WooCommerce 11.2.1, checked in source:
  - `wc_create_new_customer()` fires `woocommerce_created_customer` (`wc-user-functions.php:198`). Callers: My account, classic checkout (`class-wc-checkout.php:1252`), block checkout `process_customer` (`StoreApi/Routes/V1/Checkout.php:1005`), order confirmation Create account block (`OrderConfirmation/CreateAccount.php:154`), and the data store (finding 1). `woocommerce_new_customer` comes only from the `WC_Customer` data store (`class-wc-customer-data-store.php:148`).
  - An unknown old status (auto-draft) is reported as pending (`abstract-wc-order.php` `set_status`); Store API sets pending before payment (`Checkout.php:675`).
  - `single-product-reviews.php` has `id="reviews"`; the form shows only if `woocommerce_review_rating_verification_required` = no or `wc_customer_bought_product( '', current user, product )`. `single-product.js` opens the Reviews tab for `#reviews`.
  - `WC_Customer::set_billing_email()` throws on an invalid address (`class-wc-customer.php:1155`). `get_address_prop()` reads `changes` first, so an unsaved set is what later getters read.
- `RefResolver` already has a `user:` branch returning a bare `WC_Customer`; nothing uses it yet.
- `Dispatcher` keeps the `Trigger` object from `collect` to `dispatch`, so an instance property set during the request is there at `claim()`. Listeners attach on `init`; My account's form handler runs later, on `wp_loaded`. `exit` after its redirect still reaches `shutdown`.
- `HtmlContext::BLOCK_START` includes `table`: a block starting with `<table` replaces its paragraph.
- `DataItem::display()` passes `( $object, $html )`, and `$object` is null for `site`. `fn( $site, bool $html )`.
- `VariablePicker` builds Blocks from any `block` field the step can reach, so site blocks appear without TS changes. Its comment "blocks come from the trigger's data and from steps above" stays roughly right; adjust it only if it reads wrong.
- `is_first_order` for a `WC_Customer` means exactly one paid order: false for a new account with none. Existing behaviour, tested in CP3.
- `ProductGrid::newest()` already filters published, visible, in stock and is cached per request. Its docblock says "for previews": update it.
- `ModuleRegistryTest::STEPS` lists step ids; `StepContractTest` has an outputs map. `TriggerRefsTest` holds refs tests.

## Design

### H1 Template
`templates/high-value-order-alert/index.ts`: trigger `order_status_changed`, `{ from: ['pending','on-hold','failed'], to: ['processing','completed'], only_checkout: false }`. Condition and internal email unchanged. Description: "Email the store team when an order of an amount you choose or more is paid, so big orders get attention fast."

### R1 Review data and block
New `Core/Email/ReviewProducts`:
- `MAX = 6`.
- `for_order( \WC_Order $order ): \WC_Product[]`: `[]` when `'yes' !== get_option( 'woocommerce_enable_reviews', 'yes' )`. Line items → `$item->get_product_id()` (already the parent), unique, `wc_get_product`, `publish` and `get_reviews_allowed()`. Drop `reviewed_ids()`, slice to MAX. No static cache: read when the step runs.
- `reviewed_ids( int[] $ids, string $email, int $user_id ): int[]`: `get_comments()` with `post__in`, `type => 'review'`, `status => [ 'approve', 'hold' ]`, cache updates off. One query with `author_email`, one with `user_id`, merged; WP_Comment_Query ANDs them. Finding 7's guards. Two queries rather than filtering a `post__in` query in PHP, which would load every review of a popular product. `comment_author_email` has the site's `_ci` collation, so case doesn't matter; check-lifecycle tests it.
- `render( array $products, bool $html ): string`: none → `''`. HTML: one-line table per mock (image 64px like `CartItems`, bold linked name, right cell `EmailButton` small "Leave a review"), explicit padding and margin on every cell, `margin:0 0 16px`. Name cut like `ProductGrid::short_name` (make it public rather than copy). Link: `get_permalink() . '#reviews'`. Text: `Name — Leave a review: URL`, one per line.

`OrderData`: `has_products_not_reviewed` ("Order has products not reviewed", boolean, `(bool) ReviewProducts::for_order( $order )`), `review_products` ("Products to review", block, `conditional` false, value = text, display = `render`). `sample()`: `has_products_not_reviewed` `(bool) ProductGrid::newest()`, `review_products` from `ProductGrid::newest()`, built once per request like `sample_grid()`.

### EmailButton
New `Core/Email/EmailButton::render( string $label, string $url, bool $html, string $style = 'solid' ): string`. Text: `Label: URL`. HTML: `CartItems::button`'s table, base colour lookup and `wc_light_or_dark`. Styles: `solid`, `outline` (white background, base colour border and text, for "Visit the shop" per mock), `small` (6px 12px, 13px, no outer margin). `pair()` draws two buttons in one centered table for `welcome_links`. `CartItems::button()` calls `render`; its output stays byte-identical, checked by check-carts.

### W1 Trigger
`Triggers/CustomerAccountCreated`: `ID = 'customer_account_created'`, label "Customer account created", description "A customer creates an account", icon `user-plus`, summary "When a customer creates an account", `category()` `customer`, `provides()` `[ CustomerData::ID ]`, `fields()` `[]`, args `[ 'customer_id' => int ]`, `subject()` `user:<id>`.
- `listen`: `woocommerce_created_customer` → `$emit`; `woocommerce_new_customer` → `$this->saved_customers[ $id ] = true`.
- `claim`: false for those ids.
- `context`: `[ customer => RefResolver::account( $id ) ]`, null when gone. `refs`: `Ref( customer, 'user:<id>' )`, null when gone.
Register in `WoocommerceModule`; update its docblock.

### W2 Billing fill
`RefResolver::account( int $id ): ?\WC_Customer`, public static, used by the `user:` branch and the trigger. Guards as today, then: `'' === get_billing_email( 'edit' )` and `is_email( $user->user_email )` → `set_billing_email`; `'' === get_billing_first_name( 'edit' )` → `set_billing_first_name( $user->first_name )`. Never `save()`. A comment says why (billing meta is empty until a first checkout) and why `is_email` (the setter throws, and a legacy address shouldn't fail the whole run at its start).

### W3 Presenter
`RunPresenter::subject`: `user` → `RefResolver::account( $id )`: label `get_billing_email()`, the address `customer.email` reads (finding 15), url per finding 9; gone → "Customer #id", url null. `Executor::gone_message` per finding 8. TS unchanged: `subjectUrl` is already linked.

### W4 Privacy
`PersonalData::subjects()`: adds `user:<id>` for `get_user_by( 'email', $email )` and `get_users( [ 'meta_key' => 'billing_email', 'meta_value' => $email, 'fields' => 'ID' ] )`, unique. `RunStore::about()` then covers export, cancel and in-progress.

### W5 Site fields and blocks
`SiteData::define()`: `account_url` ("My account URL"), `shop_url` ("Shop URL"), both `conditional` false, from `wc_get_page_permalink()` behind `function_exists`. Blocks, `conditional` false: `welcome_links` ("Account and shop buttons", `EmailButton::pair`: "Go to your account" solid, "Visit the shop" outline), `shop_button` ("Visit the shop button"), `account_button` ("Go to your account button"), `new_products` ("Newest products", `ProductGrid::render( ProductGrid::newest(), $html )`). Update the description "its name and address".

### R3 Before you start
`templates/core/meta.ts`: `needsProductReviews( graph )`: `wc.active && !wc.reviewsEnabled`, and a condition rule reads `order.has_products_not_reviewed`. `AdminPage`: `wc.reviewsEnabled` (`'yes' === get_option( 'woocommerce_enable_reviews', 'yes' )`). `meta-fixture.sh` pins it `true`. `localize.d.ts`.
`TemplatePreviewPage`: a local `ProductReviewsWarning` (Alert with `CartTrackingWarning`'s tokens): "Turn on product reviews. Without them there’s nothing to review." + link "Open WooCommerce settings" → `${wp.adminUrl}admin.php?page=wc-settings&tab=products`, new tab like `FreeShippingWarning`. In the existing Before you start section, whose condition gains it. No library badge: the docs and mock have none.

### Templates
`templates/core/flow.ts` per finding 11. Greeting `Hi {{ customer.first_name | default: "there" }},`, sign-off `See you soon,\n{{ site.name }}`, To `{{ customer.email }}`. Typographic apostrophes, as the other templates.

`templates/review-request/index.ts`: local `graph( reminder )`, the cross-sell pattern: each `then` steps left. Category `post_purchase`, workflow `review_request`. `stillValid` = all of `order.status` is `completed`, `order.has_products_not_reviewed` is_true, `customer.unsubscribed` is_false.
- Common: `trigger` (order_status_changed, to `['completed']`, `from: []`, only_checkout false) → `condition` (has_products_not_reviewed is_true) → then `wait` (7 days) → `still-valid` → then `email`.
- Reminder adds: `email` → `wait-2` (7 days) → `still-valid-2` → then `email-2`.

`templates/welcome/index.ts`: `lineGraph( 'customer_account_created', … )`. Category `customer_lifecycle`, workflow `welcome`. Steps `email-1`, `wait-1`, `email-2`, `wait-2`, `email-3`.

| Template id | Name | Steps | Description |
|---|---|---|---|
| review-request | Review request | common | "7 days after an order is completed, ask for a review of each product the customer hasn’t reviewed yet." |
| review-request-reminder | Review request + reminder | common + reminder | "7 days after an order is completed, ask for a review of each product; a week later, remind once about the ones still not reviewed." |
| welcome-email | Welcome email | W-1 | "When a customer creates an account, welcome them with links to their account and the shop." |
| welcome-series | Welcome 3-email series | W-1, Wait 3 days, W-2, Wait 4 days, W-3 | "Three emails after a customer creates an account: a welcome right away, new products after 3 days, an offer to help after 7 days." |

| Email | Subject | Heading | Body between greeting and sign-off |
|---|---|---|---|
| R-1 | How did your order go? | Tell us what you think | Thanks again for order {{ order.number }}. If you have a minute, tell other shoppers what you think: · `{{ order.review_products }}` · It takes a minute and helps a lot. |
| R-2 | A quick review? | Still have a minute? | You haven’t reviewed these yet: · `{{ order.review_products }}` |
| W-1 | Welcome to {{ site.name }} | same | Your account is ready. You can track orders, save addresses and check out faster next time. · `{{ site.welcome_links }}` |
| W-2 | New in the shop | same | Here’s what just arrived at {{ site.name }}: · `{{ site.new_products }}` · `{{ site.shop_button }}` |
| W-3 | Anything we can help with? | same | Reply to this email if you have a question. Your orders and addresses are in your account: · `{{ site.account_button }}` |

## Checkpoints
Branch `jack/feature/m3-m5-lifecycle` from develop 374260a.

0. ✅ **CP0 Docs: M8 done** (34bcdb2) — Risk: 1
   - Files: `docs/ROADMAP-dev.md`: phase table M8 "✅ Done"; Status "✅ Done. Merged into `develop` (PR #32, PR #33, `374260a`)."; M8.6–M8.8 and Templates ✅.
   - Tests: none. Done: `grep -n "⏳" docs/ROADMAP-dev.md` shows no M8 line.

1. ✅ **CP1 High-value template + docs** (62a4711) — Risk: 1
   - Files: `templates/high-value-order-alert/index.ts`; `templates/templates.test.ts` (trigger config, condition ≥ 500, email unchanged); `dev/scripts/check-orders.php:91` comment (its workflow keeps `order_created`, so it no longer shows the template's shape).
   - Docs: ROADMAP-v1.0 Template details "over 500" → "500 or more"; ROADMAP-dev M5.1 states from / to / condition, known limit (processing → on-hold → processing alerts again: OrderCreated's once-per-order claim is lost); M2 follow-up "A workflow runs again…" moves to the v1.1 Backlog, merged: "Run once per order" through M6 `trigger_claims`.
   - Done: `pnpm test`.

2. ✅ **CP2 Review data, block, reviewsEnabled meta** (ca417f4) — Risk: 2
   - Files: new `Core/Email/ReviewProducts.php`, `Core/Email/EmailButton.php`; `Core/Email/CartItems.php`; `Core/Email/ProductGrid.php` (`short_name` public, `newest` docblock); `DataItems/OrderData.php`; `Engine/BEPages/AdminPage.php`; `localize.d.ts`; `dev/scripts/meta-fixture.sh`; fixture; new `dev/scripts/check-lifecycle.{sh,php}`; `tests/Unit/Core/Email/ReviewProductsTest.php` (render: none → `''`, one line, `#reviews`, text form; guards: no products, empty email, id 0 run no query).
   - Docs: ROADMAP-dev M3.1 keys; design §6 Tier 2 (review block, `EmailButton`); ROADMAP-dev follow-up on the colour lookup.
   - check-lifecycle adds: true and listed with a fresh order; reviewed by billing email; same with different case; reviewed by user id with another email; a `hold` review counts; `spam` and `trash` don't; a non-`review` comment doesn't; another customer's email doesn't; a guest order isn't matched by other guests' reviews; reviews off → false and `''`; `comment_status` closed and draft products left out; variation → parent, two variations one row; deleted product skipped; max 6; a review added after the first read counts on the next read. Output has `#reviews`, one line. Sample renders with no orders.
   - Done: `check-lifecycle`, `check-carts` (button unchanged) PASS; PHPUnit; `pnpm test` parity.

3. ✅ **CP3 Account created trigger, W2, presenter, privacy** (1777fd5) — Risk: 3
   - Files: new `Triggers/CustomerAccountCreated.php`; `Modules/WoocommerceModule.php`; `Core/Data/RefResolver.php`; `Core/Runtime/Executor.php`, `RunPresenter.php`; `Engine/Privacy/PersonalData.php`; TS per finding 10: `steps/triggers/customer-account-created/index.ts`, `steps/index.ts`, `packages/types` `StepId`, `localize.d.ts` category, `StepPicker.tsx` label "Customers"; fixture; `tests/Unit/ModuleRegistryTest.php`, `Core/Steps/StepContractTest.php`, `Triggers/TriggerRefsTest.php`, `Core/Runtime/RunPresenterTest.php`, `ExecutorTest` (message per ref form).
   - Docs: design §4 customer subject `user:<id>` and billing fill, §4 Gone entity wording; §5 Picker sections adds Customers; ROADMAP-dev M4.1, M2.8; ROADMAP-v1.0 Workflow overview "Customer links" line.
   - check-lifecycle adds: `wc_create_new_customer` → one queued run, subject `user:<id>`; no workflow → no run; `new WC_Customer … save()` → no run (finding 1); in one request, `wc_create_new_customer` for one email and `new WC_Customer … save()` for another → exactly one run, the shopper's (finding 17); `wp_insert_user` → no run. W2, on a customer with empty billing meta and a first name: `customer.email`, `first_name`, `unsubscribed` (by account email), `paid_order_count` (guest order with that email counts), `is_first_order` (0 → false, 1 → true); `billing_email` meta still empty after reads (never saved); existing billing values win. Presenter label and URL: empty billing → account email; customer with a changed billing email → label = billing email; deleted user → "Customer #id", url null; queued run of a deleted user → skipped "The customer was deleted."; waiting run of a deleted user, next email step skipped with the same text. Privacy: export lists a `user:` run; erase cancels its waiting run, by account email and by billing email; user with no runs unaffected.
   - Done: `check-lifecycle`, `check-runs`, `check-late-module` PASS; PHPUnit; `pnpm test` parity.

4. ✅ **CP4 Site fields + blocks** (e77fa66) — Risk: 2
   - Files: `DataItems/SiteData.php`; `Core/Email/EmailButton.php` (`pair`); `tests/Unit/DataItems/` if a pure part shows; fixture; `tests/fixtures/variables.json` if the parity test needs it.
   - Docs: design §6 Tier 2 (site blocks); ROADMAP-dev M4.2; `.claude/docs/context-php-backend.md` (`EmailButton`, `ReviewProducts`, triggers, data items, `check-lifecycle`).
   - check-lifecycle adds: URLs equal WooCommerce's pages; `welcome_links` has both, one line, base colour; single buttons; `new_products` up to 4 newest, published, visible; none → `''`. Preview of a customer trigger email: `sample` true, Jane, real `site.name`, real links.
   - Done: `check-lifecycle` PASS; `pnpm test` parity; send W-1 through the mail catcher.

5. ✅ **CP5 Templates, picker, Before you start, cross-sell greeting** (f0e693f) — Risk: 2
   - First, before any change: dump every existing template graph (JSON, sorted keys, by template id) to a scratch file outside the repo, through a throwaway test or script that is never committed.
   - Files: `templates/core/cartFlow.ts` → `flow.ts`; `templates/abandoned-cart/index.ts`, `abandoned-checkout/index.ts` (imports, names); `cross-sell-upsell/index.ts`; new `templates/review-request/index.ts`, `welcome/index.ts`; `templates/index.ts`; `templates/core/meta.ts`; `templates/templates.test.ts`; `pages/template-preview/TemplatePreviewPage.tsx`; `steps/triggers/customer-account-created/index.ts` → `.tsx` with a Footer ("No settings needed.", same class as `CartTriggerNotes`).
   - Tests: existing `it.each` saves and activates each; versions linked (2 + 2); review waits and `still-valid` rules; reminder only after `email`; welcome waits 3 + 4; welcome has no condition or coupon; every email's To, greeting, sign-off, subject and heading per table; blocks on their own paragraphs; cross-sell greeting has the default; `needsProductReviews` on / off / WooCommerce off / other templates; preview note and link; `StepPicker` shows Customers if a test exists.
   - Done: stop hook green; `./run.sh meta-fixture` unchanged; dev registry throws nothing; after the `flow.ts` refactor, the same dump of the existing template ids diffed against the first one shows only the cross-sell greeting lines (the `message` line of each of the 4 cross-sell templates, greeting changed, rest equal).

6. ✅ **CP6 Full run + docs + PR text** (1175964) — Risk: 2
   - Files: `dev/scripts/check-lifecycle.{sh,php}` (H1: temporary workflow with the template's trigger and condition; pending → processing, on-hold → processing, failed → processing start it; completed → processing and processing → completed don't; total 499 stops at the condition, 500 passes; a new order through auto-draft reads as pending; processing → on-hold → processing runs twice, the known limit); `.claude/plans/dev/028-pr.md`.
   - Docs: decisions: new "M3–M5. Lifecycle workflows" (Contents row, design §11 row): why status change over order created, why reviews read when the step runs, why two comment queries, why the data store guard, why billing fill in memory, why site isn't provided. Cost lines = Known limits. ROADMAP-v1.0: limits R2, Editor and Template Library "Today" lines, Customer emails "Today"; design status line if it names what's built. ROADMAP-dev: M3–M5 phase table and items ⏳.
   - Run: `./run.sh check-lifecycle`, `check-carts`, `check-runs`, `check-orders`, `check-status-flow`, `check-storage`, `check-late-module`; PHPUnit, phpcs, `pnpm build`, `pnpm lint`, `pnpm test`.
   - Docs grep over `docs/` and `.claude/docs/` for `products_not_reviewed`, `over 500`, `welcome email block`, `customer links with M4`, `Customer links come`, `runs again when the same order`. Allowed hits: `has_products_not_reviewed`; nothing else.
   - Symbol search: `ReviewProducts`, `EmailButton` (`render`, `pair`), `short_name`, `CustomerAccountCreated`, `RefResolver::account`, `saved_customers`, `has_products_not_reviewed`, `review_products`, `account_url`, `shop_url`, `welcome_links`, `shop_button`, `account_button`, `new_products`, `reviewsEnabled`, `needsProductReviews`, `ProductReviewsWarning`, `lineGraph`, `customerEmail`; removed: `cartGraph`, `cartEmail`, `cartFlow`, cross-sell's `greeting`, `signOff`, `email`, `rule`.

## Browser steps for Jack
R = `SELECT id,subject,status,error FROM wp_yaymatik_runs ORDER BY id DESC LIMIT 10;` Compare each screen with its mock frame, light and dark, minus the overrides above.

1. **#reviews, classic theme:** Use Review request; complete an order; run the wait from Scheduled Actions or edit it to 1 minute. Email has the products; the button opens the product's Reviews tab.
2. **#reviews, block theme (Twenty Twenty-Five):** same link. If the tab doesn't open or the anchor is missing, tell me: R2 says classic only, so the note may need rewording.
3. Leave a review as that customer (held for moderation too), run the reminder → the product is gone from R-2. All reviewed → run ends at the Condition's No path.
4. Turn off Enable product reviews → Before you start shows the note and link, Activate still works; turn on, reload → note gone. Run: condition No.
5. **Account created via My account, classic checkout and block checkout** (three new emails) → R shows `user:<id>` for each, once; email arrives in time with greeting and links; run subject = email, linked to the user.
6. Order confirmation Create account block, if the store shows it → same.
7. WordPress Users → Add New → no run. `wp wc customer create --email=<new email> --user=1` (goes through REST → data store) → no run.
8. Welcome 3-email series with waits shortened: W-2 grid and shop button, W-3 account button; open in Gmail or Outlook web if you can. Unsubscribe from W-1 → W-2 skipped with its reason.
9. Delete the user while waiting → next step skipped "The customer was deleted."; queued run → Skipped; label "Customer #id" with no link.
10. Tools → Export / Erase Personal Data for that email: the run is listed; erase cancels the waiting run.
11. High-value alert: COD order ≥ 500 → alert at processing; bank transfer → none on-hold, alert when moved to processing; a paid downloadable order → alert at completed; 499 → condition No. Block and classic checkout.
12. Picker: WooCommerce → Order, Carts, Customers; `tAccount` panel "No settings needed."; Insert variable → Blocks shows Site blocks in Message, none in Subject; Order shows Products to review; Condition offers Is true / Is false.
13. Preview: all 4 new templates' emails, Desktop / Mobile, with an order and with none ("Sample data"). Review rows on a phone width; a long product name.
14. Library: Post-purchase and Customer lifecycle counts; Other versions; cross-sell preview greeting with an order without a first name.

## Known limits
Go into the docs per the Decisions table.
- High-value: processing → on-hold → processing or completed alerts again; "Run once per order" → v1.1. A late payment on a cancelled order doesn't alert.
- An erase keeps the WordPress account, so the run label still shows its email until the user is deleted.
- Reviews: WordPress "Users must be registered and logged in to comment" sends guests to a login. WooCommerce "verified owners only" hides the form for guest-order buyers. The `#reviews` anchor is checked on classic templates only.
- `wc.reviewsEnabled` stays stale until the page reloads.
- Welcome: bot sign-ups get welcome emails. Admin-created and imported users don't start it, including REST and code that saves a new `WC_Customer`.
- A customer deleted during a wait: later emails are skipped and the run ends Completed. After the WP user is deleted, its runs stay until run retention, and the eraser finds them only by the email in their step output.
- W-2 with no visible products sends its text and shop button without a grid. "New" means the store's newest, however old.
- `customer.full_name` on an account with no billing details is the first name only, and `last_name` stays empty: W2 fills email and first name only.

Noticed, left alone (mention in the report): the cross-sell subjects `Picked for you, {{ customer.first_name }}` and `Ready for an upgrade, {{ customer.first_name }}?` still read oddly with no name. The brief covers the greeting only.

## Out of Scope
Win-back (029); coupons in welcome; review reward; negative review alert; once-per-order guard; a stop on deleted users; privacy policy text on review and welcome emails; library badge for reviews; hints in the Condition and status fields.

## Deviations
- Fixture: `./run.sh meta-fixture` on this site adds its `ppcp-gateway` payment method; left out of the commit (the script says a plain install owns those lists).
- CP5 trigger Footer removed: `StepPanel` already draws "No settings needed.", same class, for a step with no form, so the panel matches `tAccount` without one and the step stays `index.ts`.
- PR review (Jack, 4f90b2b): To gains Completed, which removes the digital-order limit; the erased run label stays and is a known limit. Kept from the removed limit line: a late payment on a cancelled order doesn't alert. Template description now says "is paid".
- CP6 known limits gain two the review found: an importer calling `wc_create_new_customer()` directly does start the welcome; a sign-up outside WooCommerce doesn't.
- CP5's `fix` commit bd40005 went in with the hook red (Prettier); 7e3d43c fixes it. CP6's review ran over the whole plan diff after the commit, as one pass.
- Review (CP2) beyond the design: `parent => 0` so a reply to a review isn't the customer's review; lines fully refunded are left out of the block; the text form keeps the full name.

## Meta
- Target branch: `jack/feature/m3-m5-lifecycle` (from develop 374260a; M8 merged as #32, #33)
- Base branch: develop
- One PR; Jack pushes and opens it by hand (merge commit) after a zip review.
