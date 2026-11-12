# Workflow Core — decision notes

**Confirmed:** 2026-10-07 · **Design:** [workflow-core-design.md](workflow-core-design.md) · **Roadmap:** [ROADMAP-dev.md](ROADMAP-dev.md)

Why each decision was made, what else was considered, and what it costs. Read this before arguing to change one. The design doc says *what* we build; this doc says *why*.

Each note: **Decision** · **Why** · **Rejected** · **Cost** · **For devs** (what changes in your daily work).

## Contents

| ID | Decision | Confidence |
|---|---|---|
| [C1](#c1-workflow-core-moves-out-of-engine) | Workflow Core moves out of `Engine/` | High |
| [C2](#c2-blocks--steps) | Blocks → Steps | High |
| [C3](#c3-store-runs-and-step-results) | Store runs and step results | High |
| [C4](#c4-keep-action-scheduler) | Keep Action Scheduler | High |
| [C5](#c5-run-in-slices) | Run in slices | High |
| [C6](#c6-scope-stores-refs-not-objects) | Scope stores refs, not objects | High |
| [C7](#c7-step-outputs-scopes-typed-data) | Step outputs, scopes, typed data | High |
| [C8](#c8-runs-pin-a-version) | Runs pin a version | High |
| [C9](#c9-stored-causation-for-loop-safety) | Stored causation for loop safety | High |
| [C10](#c10-custom-tables-replace-the-cpt) | Custom tables replace the CPT | High |
| [C11](#c11-scheduled-and-query-triggers-with-claims) | Scheduled and query triggers with claims | High |
| [P7](#p7-tree--container-steps-no-merge) | Tree + container steps, no merge | Owner |
| [L2](#l2-step-settings-defined-in-php) | Step settings defined in PHP | Owner |
| [L3](#l3-own-template-module-no-liquid) | Own template module, no Liquid | Owner |
| [L4](#l4-foundation-before-m1) | Foundation before M1 | Owner |
| [L5](#l5-step-icons-and-plugin-logos) | Step icons by name, plugin logos as SVG | Owner |
| [P1](#p1-runtime-free-free-vs-pro-by-folder) | Runtime free; Free vs Pro by folder | Owner |
| [P2](#p2-yay-plugins-own-their-steps) | Yay plugins own their steps | Owner |
| [P3–P6](#p3p6-smaller-decisions) | Cancel on Update, API last, AI later, Health check | Owner |
| [M7](#m7-cart-tracking) | Cart tracking | Owner |
| [M8](#m8-abandoned-carts) | Abandoned carts | Owner |
| [M3–M5](#m3m5-lifecycle-workflows) | Lifecycle workflows | Owner |
| [M6](#m6-win-back) | Win-back | Owner |
| [CT](#ct-custom-workflow-catalog) | Custom workflow catalog | Owner |
| [PI](#pi-privacy-lookup-index) | Privacy lookup index | Owner |

"High" = recommended and accepted as-is. "Owner" = debated and decided by the product owner.

---

## C1. Workflow Core moves out of `Engine/`

**Decision.** `includes/Engine/` keeps only WP/WC glue (BEPages, API, Register). Workflow code moves to `includes/Workflow/`.

**Why.**
- In this repo "Engine" means "hooks into WP and WC". Runtime code mixed into it makes both harder to find.
- REST controllers should call the workflow code, not contain it. Then the same logic serves the editor, WP-CLI, imports and the AI.
- "Runtime Engine" as a name would add a third meaning to "engine".

**Rejected.** Keep `Engine/Workflows/` and add folders next to it: cheaper, but the confusion grows with every new folder.

**Cost.** One mechanical PR (F0, ~1 day) and `composer dump-autoload`.

**For devs.** New workflow code goes in `includes/Workflow/`. `Engine/` is only for admin pages, REST routes and script loading.

## C2. Blocks → Steps

**Decision.** Rename "Blocks" to "Steps" in PHP and TS.

**Why.**
- "Block" clashes with Gutenberg blocks, which every WP dev knows.
- The UI already says "step", as most automation tools do.

**Cost.** Part of F0. Done once, while no branch is open, to avoid conflicts.

**For devs.** `Step`, `Trigger`, `Action`, `Condition` classes; TS folder `routes/workflows/steps/`.

## C3. Store runs and step results

**Decision.** New tables `runs` and `run_steps`. Every run and every step result is stored.

**Why.** Almost everything on the roadmap needs it:
- Wait (a run must survive for days)
- Run history and run detail (M2)
- Cancel on Deactivate (v1.0 release rule 4)
- Retries and replay (v1.1)
- AI "explain this failure" (v2.0)

Today errors only go to the WooCommerce log, which merchants never open.

**Rejected.** Logging only: can't pause, resume or show history.

**Cost.** Table growth → retention setting and cleanup (M7.4), Health check warning (§9).

**For devs.** A step's `run()` returns a `StepResult` (ok with outputs / skipped with a reason / failed). That result is what the run detail shows.

## C4. Keep Action Scheduler

**Decision.** Action Scheduler stays as the clock and the worker. Our `runs` table is the source of truth for state.

**Why.**
- Bundled with WooCommerce and already used. Battle-tested, with its own admin screen for debugging.
- A custom queue would rebuild what it already does: retries, locking, the WP-Cron fallback.

**Rejected.** Our own queue table + cron: more code, same WP-Cron limits.

**Cost.**
- On low-traffic sites WP-Cron runs late, so Waits finish late → Health check proposes a real server cron.
- The AS table grows → we clean up our own group.

**For devs.** Don't read AS tables to know a run's state; read `runs`. AS only says "wake up and run slice N".

## C5. Run in slices

**Decision.** One AS job keeps running steps until the run ends, hits a Wait, uses ~20 s, or ends a loop batch. Then it saves its position and queues the next slice. A row lock stops two workers from taking the same run.

**Why.**
- One job per whole run (today) can't pause for a Wait, and a big loop would time out.
- One job per step would flood AS with thousands of jobs and make each run slow.

**Cost.** The executor must save and restore its position (cursor), and every step must be safe to resume after.

**For devs.** Don't keep state in PHP variables across steps. Put it in step outputs or `vars`, which are saved.

## C6. Scope stores refs, not objects

**Decision.** A run stores entities as refs like `{type: 'order', id: 12}` and loads them when a step needs them. Facts only true at trigger time (status from/to, cart contents) are copied in.

**Why.**
- A `WC_Order` object can't be saved between slices.
- After a 7-day Wait we want *current* data ("has the customer reviewed yet?"), not a week-old copy.
- Rows stay small.

**Rejected.**
- Live objects (today): can't survive a Wait.
- Full snapshots: stale data, large rows, and more personal data stored.

**Cost.** The order may be deleted before the run reaches the step. Rule: the step is skipped and run history says why.

**For devs.** Read data through the scope (`$scope->get( 'order.total' )`), never by caching objects in the step.

## C7. Step outputs, scopes, typed data

**Decision.** Each step declares outputs. Variables are paths in scopes: `trigger`, `steps.<id>`, `loop`, `vars`, `site`, … Types add boolean, money, list, entity and relations (`order.customer`, `order.items`).

**Why.**
- M1 needs it right away: Generate coupon → coupon code in the next email.
- Yes/no conditions (first order, unsubscribed) need booleans.
- For each needs lists.
- The Validator can work out exactly which variables each step may use. That powers "Insert variable shows only what this step can use" (v1.0) and template checks.

**Cost.** The largest part of F1: a data layer rewrite.

**For devs.** A new step declares its outputs in `outputs()`. You never hand-write which variables the editor offers.

## C8. Runs pin a version

**Decision.** A run keeps the version it started with. A version's logic never changes; its layout may.

**Why.** Today a queued job reads whatever graph is live when it runs. With Waits, a 3-email series could start on version 1 and finish on version 3 with different steps. Pinning makes every run follow one known graph, and run detail can show it.

**Cost.** Versions are only ever added, and their logic is never edited, except the draft row's, which is edited in place. Moving a card rewrites a row's layout and nothing else.

**For devs.** Never load "the live graph" inside the executor. Load `runs.version_id`.

## C9. Stored causation for loop safety

**Decision.** Each run stores `parent_run_id`, `root_run_id`, `depth`. Max depth, plus a per-workflow rate limit that pauses a runaway workflow.

**Why.** Today's guard is an in-memory list passed in job args. It works for one request, but not across Waits, Call workflow or scheduled triggers, which can loop over hours.

**For devs.** Anything that starts a run from inside a run (Call workflow, an action that fires a trigger) passes the parent run.

## C10. Custom tables replace the CPT

**Decision.** `workflows` and `workflow_versions` tables replace the `yaymatik_wf` post type and its meta.

**Why.**
- Every store event runs a `meta_query` to find active workflows. That's slow, and it runs on checkout.
- Graph JSON in post meta needs slashing tricks.
- Several meta writes per save can half-fail.

**Rejected.** Keep the CPT and cache the trigger lookup: fixes speed, keeps the rest.

**Cost.** It **must ship before v1.0**: after release it would touch merchants' data. Nothing has shipped, so dev data is reset, no migration (F2). We lose WP's export tool → JSON import/export later.

**For devs.** Workflows are read through `WorkflowStore`, never `get_posts()`.

## C11. Scheduled and query triggers with claims

**Decision.** A schedule fans out to one run per matching subject, in batches. Table `trigger_claims` records "this workflow already ran for this subject"; it has no end date, and a run that doesn't start gives its claim back.

**Why.**
- Inactive customer (M6) and abandoned cart (M8) aren't events. Something has to look for them on a schedule.
- Without claims, the daily check would email the same customer every day.
- It generalises today's trick in `OrderCreated::claim()` (an order meta flag).

**For devs.** A query trigger opts in with `Trigger::claims_per_workflow()` and keeps its usual `subject()`. A daily job of its own finds the subjects in batches and fires the trigger's event; the Dispatcher takes the claim.

**Note (M7).** Abandoned carts come from the `carts` table, not from pending orders: WooCommerce cancels unpaid orders after the hold-stock minutes.

**Note (M8).** Claims are built in M6. Abandoned carts need none: see [M8](#m8-abandoned-carts).

**Note (M6).** The claim is per workflow and order, not per customer with a time limit: see [M6](#m6-win-back).

**Note (CT).** The claim column holds a key, not always the run's subject: the milestones claim per person while their runs stay about the order. See [CT](#ct-custom-workflow-catalog), D5.

---

## P7. Tree + container steps, no merge

**Decision.** Each step has one parent. Conditions and Switch branch. For each is a container with `body` and `done`. **Branches never merge.**

**What merge would add.** Steps shared by two branches written once instead of copied: less duplication, a smaller canvas, and one step id for stats.

**Why not.**
- None of the 18 v1.0 templates need it. Popular automation tools don't merge branches either.
- The real cost is variables, not drawing. After a merge, a coupon made in only one branch may not exist, so the Validator, "Insert variable" and the AI would all need "maybe exists" logic.
- Call workflow (v1.1) covers shared steps: put them in their own workflow.

**Cost.** Some step duplication for complex workflows.

**For devs.** The Validator enforces one parent per step. Don't build merge support.

## L2. Step settings defined in PHP

**Decision.**
- PHP declares each step's settings (`fields()`). The editor builds its valibot checks from them. **Validation always runs in Automation.**
- A small generic form draws simple steps.
- Core steps with complex forms import their own React form directly.
- Plugin steps with custom forms wait for the public API, P4.

**Why.**
- Today each step's rules are written twice: PHP `validate()` and TS `schema.ts`, kept in sync by hand. A mismatch means the editor says OK but Activate fails, or the reverse.
- One source fixes that and also:
  - Translations live in one place.
  - Options like order statuses come straight from PHP.
  - Templates can be checked in CI.
  - The AI reads the same rules (v2.0).
- With the generic form, a simple Yay plugin step needs PHP only: no JS bundle, no build pipeline.

**Options considered for the schema source.**

| Option | Verdict |
|---|---|
| A. Plugin passes its own valibot schema with its panel | Rejected: the plugin's PHP must mirror it by hand, so the same drift moves into every plugin |
| **B. Built from the PHP fields** | **Chosen** |
| C. Ask the server on every change | Rejected: a network call on every keystroke |

**Keep it small.**
- About 8 widgets plus `show_if`. A form that needs more gets a custom panel, not a new widget.
- Otherwise the field list slowly turns into a second UI framework in PHP.

**Cost.**
- Porting the 7 existing steps (in F1).
- Generic forms are less flexible.
- Settings rules only in PHP `validate()` show after a save, not while typing.

**For devs.**
- Simple step: one PHP class.
- Complex core step: PHP class + a React form passed to `defineStep()`, same props `{ config, onChange }` (`StepFormProps`).
- Never write valibot rules for a step by hand.

## L3. Own template module, no Liquid

**Decision.** Our own `{{ path | filter: arg }}` syntax, no template library ever. It grows in tiers only when needed.

**Why change at all.** Today's `{{ item.field }}` has two parts only. It can't reach `{{ steps.coupon.code }}` or `{{ order.customer.first_name }}`, and it can't format: no fallback for a missing name, no date or money format.

**Options considered.**

| Option | Verdict |
|---|---|
| Paths only | Rejected: every format needs its own special variable |
| **Small syntax: paths + ~6 filters** | **Chosen** |
| Full Liquid (PHP library) | Rejected by the owner: no dependency. Also heavy, a security risk with merchant-written templates, and it puts logic in text instead of the workflow |

**How the extra needs are met without template logic.**
- **Lists** (cart items, products): server-rendered email blocks, already planned for v1.0.
- **Conditional text:** a "Show if" setting on a block, using the same rules builder as the Condition step. One way to write logic, everywhere.
- **If still not enough:** `{% if path %}` with a truthy check only. Added only if users ask.

**Cost.** We own the parser (~200 lines PHP, ~80 lines TS parse-only).

**For devs.**
- Render only in PHP. TS parses for errors and autocomplete.
- Preview and Send test call the server.
- New filters go in the filter registry as `Filters::add( $name, $apply )`. A filter never fails: input it can't use comes back unchanged.

## L4. Foundation before M1

**Decision.** Jack does everything, in this order: F0 (move + rename, 1 day) → F1 (foundation, ~3 weeks) → M1 → M2 → F2 (workflows table) → the rest of v1.0.

**Why.**
- M1 needs step outputs, templates and the new step contract. M2 needs stored runs and the executor.
- Built on the v0.1 runner, both would be built twice.
- Only the folder move and the workflows table aren't needed by M1/M2:
  - The move goes first: cheapest when no branch is open.
  - The table goes after M2, but before release.

**Options considered.**

| Option | Verdict |
|---|---|
| **F0 + F1 before M1** | **Chosen** |
| Foundation between M1 and M2 | Rejected: M1's coupon-in-email built on the old runner, then rebuilt |
| Second dev on the foundation in parallel | Not available |

**Cost.** M1 starts ~3 weeks later. M1 and M2 then go faster.

**For devs.** Until F1 is done, don't start M1 work that touches steps or emails. M1 UI pieces that don't touch the engine (Template Library filters, preview "Other versions") can fill gaps.

## L5. Step icons and plugin logos

**Decided 2026-10-08.** Source: [plan-identity.md](plan-identity.md).

**Decision.** Two kinds of icon, two formats:
- **Step icon** = what a step does (mail, coupon, clock). The step's `icon()` returns a name from a fixed list in core (lucide, about 40 icons).
- **Plugin logo** = who provides it (WooCommerce, YayMail). Each module gives the URL of one one-colour SVG file its plugin ships; the admin app draws it as a CSS mask in the tile's text colour.

**Why.**
- Since L2, a step can be PHP only, so the editor can't know its icon unless the catalog sends it.
- A name keeps every step icon in one style, lets the editor tint it by kind and theme, and carries no markup, so nothing to sanitize.
- Lucide has no brand logos, and a plugin needs its own mark in the library and picker. One SVG per plugin is enough.

**Rejected.**
- **SVG for every step:** mixed styles, colors that break in dark mode, and markup to sanitize for each step.
- **Lucide names for plugins too:** no brand logos.
- **Any lucide name, loaded on demand:** pulls the whole icon set into the admin bundle.

**Cost.**
- Core owns the step icon list; a plugin that needs a new icon asks core to add it.
- Each Yay plugin needs a small logo that works in light and dark.

**For devs.**
- New step: `icon()` returns a name from the list. Never return SVG or a URL.
- New plugin: ship one one-colour SVG (viewBox 0 0 24 24) and return its URL from the module's `logo()`. Its colours never show.
- Logos are files, not markup in PHP: a Yay plugin defines its module in its own repo, and there is no SVG to sanitize. Cost: the mask loads in CORS mode, so a CDN without CORS headers shows a solid tile.

## P1. Runtime free; Free vs Pro by folder

**Decision.** The runtime is 100% free. Steps and data items belong to modules, one per plugin. Free vs Pro is the folder: Pro modules live in `includes/Pro/`, extend the free ones, and the free zip leaves the folder out. An unfinished step is simply not registered.

**Why.**
- A free runtime keeps Lite useful and makes Yay plugins' steps work for everyone.
- Merchants pay for features, not for the ability to run them.
- The folder is the tier: Pro code is absent from the free zip, so there is nothing to switch on in it. No tier flags or switches to keep in sync.
- Not registering an unfinished step keeps it out of a release without deleting it.

**Rejected.** A manifest with a tier and an on/off switch per module (F1): Pro code still shipped in the free zip, and the switches duplicated what registering already says.

**For devs.** Every new step is listed in its module. A Pro step goes in `includes/Pro/` and its Pro module. Never check "is Pro" inside a step. Another plugin adds its module on `yaymatik_modules` before `init`.

## P2. Yay plugins own their steps

**Decision.**
- Each Yay plugin ships its own steps, templates and data types.
- YayMatik Pro owns templates that combine several plugins.
- A static feature directory in core lists everything and where to install it.

**Why.**
- The team that knows YayMail builds the YayMail steps, and they ship with YayMail's releases.
- The feature directory lets the editor say "Install YayMail to use this", and gives the AI the same knowledge.

**For devs.** Yay plugins use the internal registry until the public API is frozen (P4). Breaking changes must be agreed with those teams.

## P3–P6. Smaller decisions

| ID | Decision | Why |
|---|---|---|
| P3 | Deactivate / Update always cancel waiting runs in v1.0 | Safe and simple; v1.0 release rule 4. "Finish on the old version" can come later, as runs already pin versions (C8) |
| P4 | Public extension API comes last, after Pro connectors and Yay integrations are stable | A public API is a promise. Freezing it before our own plugins have used it would lock in mistakes |
| P5 | AI provider not chosen yet | It depends on price, privacy and WP's own AI work in 2027. v1.0 only keeps a provider interface open |
| P6 | Settings → Health check | WP-Cron, big stores and email delivery are the top reasons "it doesn't work". Detecting them and proposing a fix saves support time |

## M7. Cart tracking

**Decision.** Carts of logged-in customers and guests are stored in their own `carts` table, one row per WooCommerce session.

**Why.**
- Carts, not pending orders: WooCommerce cancels unpaid orders after the hold-stock minutes, and most abandoned carts never become an order.
- No own tracking cookie: WooCommerce's session already identifies the browser.
- Capture any email with the same response: no account enumeration, and guest checkouts of registered customers still count.
- The restore GET writes nothing: mail scanners open links.
- Erase anonymizes runs and keeps the unsubscribe record: run history stays countable, and the address keeps getting no emails.
- Erase also cancels waiting runs about the person's orders and carts (by `subject`): a run stores refs, not the email, and would still send.

**Cost.**
- A guest is unknown once their WooCommerce session ends (2 days).
- A spoofed `X-Forwarded-For` skips the per-IP capture limit and can drain the site-wide one for 15 minutes.
- An active row keeps the `order_id` of an unpaid order after its cart changes, so paying that order later closes the newer cart too. A missed reminder is better than reminding a buyer.
- A restored cart moves to the browser that first changes it; the browser it came from starts a new row without its email, unless it opens the link again.
- Restore is one click: a page on another site can make a browser open a valid link; the saved items go into that browser's cart, and an empty guest checkout gets the cart's email. A confirm page before the merge would stop this; not added in v1.0.
- Saved lines keep no cart item data, so products with add-on options come back without them.
- A queued or running run about the person is left as is; the admin erases again a few minutes later, and the carts stay until then.

## M8. Abandoned carts

**Decision.** A 5-minute job marks carts abandoned. Each abandon fires `yaymatik_cart_abandoned` with its kind, cart or checkout, decided at that abandon. The cart is locked only when an active workflow starts its series: `series_at`, `abandoned_at` and `abandon_trigger` are set in one UPDATE that requires `series_at IS NULL`. No `trigger_claims` table.

**Why.**
- One cart has one row, and only a new cycle (after an order) clears `series_at`, so a per-row column already answers "did this cart start a series yet". The single UPDATE is the lock, so Cart and Checkout workflows can never both start for one cart.
- Plan 033: until then the first abandon locked the cart, even when no workflow took it. A shopper idle 15 minutes while browsing, then leaving at checkout, never got the Checkout reminder. Now an abandon nobody takes uses nothing up.
- The hook changed with it: it fires on every abandon, also of a cart already in a series (not once per cycle), and gains `$kind`. It is experimental like the other hooks, so it can change before Extension API v1.
- A cart already in a series that is abandoned again records a Skipped run, "This cart already got a reminder series.", in the workflows of that abandon's kind. Testers and store owners repeating a test with the same cart see why nothing was sent, instead of nothing at all. `claim()` refuses it before `start_series()`, so a cart still gets at most one series.
- A shopper who returns turns the row active and keeps `series_at`, so leaving again can't start a second series.
- The sweep only looks 24 h past the abandon time, so turning tracking on doesn't email old carts.
- A series goes on when the shopper returns: coming back without ordering is not a reason to stop reminding. Only an order, a gone cart or an unsubscribe stops it.
- Cart emails show the prices the shopper saw, with the product's live name, image and link: the reminder must match the cart, and a price the shop raised since would read as a bait. The cart page may show a newer price after restoring.
- One series per email in 7 days, read from `series_at`, set when an active workflow takes the cart: two carts of one shopper, in one browser and another, would otherwise send two series.

**Cost.**
- A cart abandoned before its workflow was activated starts it only if the shopper returns and leaves again.
- A cart idle over 24 h past the abandon time is skipped for good, also when the job itself was that late (a store with no visits and no server cron).
- A cart a workflow took but couldn't start (rate limit, no live version) still blocks that email for 7 days.
- Emptying a cart deletes its row and its claim, so a new cart can start a new series; one series per email limits this.
- Events fire at shutdown of the sweep: a crash between marking and that shutdown loses that cart's series.
- The job adds 288 Action Scheduler rows a day, even with tracking off; Action Scheduler's own retention removes them.
- A shopper who keeps coming back to a cart in a series adds one Skipped run per abandon, and each counts toward the 1000-runs-per-minute ceiling; a sweep is at most 200 carts.

## M3–M5. Lifecycle workflows

**Decision.** The high-value alert starts on a status change, to Processing or Completed. A review email reads what's left to review when its step runs, with two comment queries. Customer account created refuses accounts saved through `WC_Customer`. An account's empty billing details are filled in memory. The account trigger provides `customer` only.

**Why.**
- **Status change over Order created.** An order is created before it's paid, so Order created would alert on bank transfers that never arrive. Moving to Processing means the money is there or the order is ready to fulfil. To also has Completed because WooCommerce's `payment_complete()` sends an order that doesn't need processing (virtual and downloadable) straight there. From is Pending, On hold or Failed, so processing → completed, the usual end of an order, and completed → processing, a correction, don't alert.
- **Reviews read when the step runs.** The 7-day wait is the point: a customer who reviewed meanwhile, or a store that closed reviews, must count. Nothing is stored at trigger time.
- **Two comment queries.** `WP_Comment_Query` ANDs `author_email` and `user_id`, and a customer may review under either. One query by product and filtering in PHP would load every review of a popular product. An empty `post__in` or `author_email` is ignored and `user_id` 0 matches every guest, so either could mark everything reviewed: `ReviewProducts::reviewed_ids()` skips those.
- **The data store guard.** `WC_Customer_Data_Store::create()` calls `wc_create_new_customer()`, so every saved `new WC_Customer` looks like a sign-up: REST and importers would email their whole list. Only that path fires `woocommerce_new_customer`, right after, so `claim()` refuses those ids per id: a request that holds both a shopper and a saved customer starts the shopper's run only.
- **Billing fill in memory.** `customer.*` reads billing details, empty until a first checkout. Saving them would write to the customer's address book from a read, and could fill checkout with values they never typed. The fill is at most two setters per load.
- **`site` isn't provided.** It's always available without a ref. Listing it would make Preview take `site.name` from a sample, which is empty, so it reads live: the URLs are public and the newest products are what the run sends.

**Cost.**
- High-value: processing → on-hold → processing or completed alerts again; "Run once per order" is in the v1.1 backlog. A late payment on a cancelled order doesn't alert: it moves from Cancelled.
- An erase keeps the WordPress account, so the run label still shows its email until the user is deleted.
- Reviews: WordPress's "Users must be registered and logged in to comment" sends guests to a login. WooCommerce's "verified owners only" hides the form for buyers of a guest order. The `#reviews` anchor is checked on classic templates only.
- `wc.reviewsEnabled` stays stale until the page reloads: it changes in WooCommerce's settings.
- Welcome: bot sign-ups get welcome emails. Admin-created and imported users don't start it, including REST and code that saves a new `WC_Customer`. An importer that calls `wc_create_new_customer()` directly looks like a shopper and does start it. A sign-up that doesn't go through WooCommerce (`wp-login.php` registration, a plugin calling `wp_insert_user()`) doesn't start it.
- A customer deleted during a wait: later emails are skipped and the run ends Completed. After the WP user is deleted, its runs stay until run retention, and the eraser finds them only by the email in their step output.
- The second welcome email with no visible products sends its text and shop button without a grid. "New" means the store's newest, however old.
- `customer.full_name` on an account with no billing details is the first name only, and `last_name` stays empty: the fill covers email and first name only.

## M6. Win-back

**Decision.** A daily job finds each person's last real order that is N to N+7 days old and starts the workflow once for that order. A claim in `trigger_claims` records it, per workflow and subject `order:<id>`, with no end date. A run that doesn't start gives its claim back.

**Why.**
- **A latest-order lookup, not a date filter.** Two lookups, by billing email and by account, read the person's newest order, and the candidate stays only if both return it. A "no newer order" filter costs the same, but fires twice for two orders in the same second; with `date ID` the higher id is the last.
- **The last real order, one set for both sides.** Real means every status except pending, failed, cancelled and checkout draft, for the candidates and for the lookups alike. A customer with a newer on-hold, refunded or paid order must not get "we miss you" and a coupon. With two sets, a newest pending or refunded order could block win-back for good, as WooCommerce may never cancel a pending order. A status another plugin adds counts as real.
- **Pending doesn't count as ordering.** Nothing was bought, and the order may sit there for ever.
- **A window, not an exact day.** An order stays findable for 7 days, so a late job or a failed start gets another try. It's also why activating on a store never emails its whole history.
- **Per workflow and last order, not per customer per period.** The subject is the order, so a later order that also goes quiet starts a new period by itself, with no time limit to tune. Two workflows each start once.
- **Release when no run starts.** The claim has no end date, so a rate-limit pause, a turned-off workflow or a failed write would otherwise lose that customer for good. The next day's sweep retries inside the window.
- **Anonymized orders are skipped.** WooCommerce's eraser and its "retain completed orders" setting replace the billing email with a placeholder, so a store that anonymizes after 3 months has those orders in the 90-day window.
- **The sweep flushes per batch.** A long Action Scheduler process has one shutdown for many batches, which would start all their runs together.

**Cost.**
- A last order older than N+7 days at activation never starts it.
- Cron late over 7 days loses those customers.
- An order placed between the sweep and the run still gets the email.
- About 2 lookups per candidate, unindexed on posts stores, repeated daily while an order is in the window; the Dispatcher adds its per-event reads. 200 per 2 minutes is at most 144,000 candidates a day.
- A crash between the claim and the run loses that order's start, and so does turning the workflow off in the instant between the Worker's check and the Executor's. Other runs that couldn't start are retried.
- Activating a second win-back version emails every customer still in the window again: claims are per workflow.
- A person is an account plus a billing email: orders under another email as a guest count as someone else.
- An on-hold order that is never paid counts as the last order: the customer gets win-back N days after it.
- A pending order doesn't count as ordering: a customer who left a payment unfinished still gets win-back.
- Claims of live workflows are never deleted: one small row per customer per last order.
- Changing days on a live workflow doesn't restart orders it already claimed.
- A run doesn't stop when the customer orders during a Wait (no `stop_reason`); the v1.0 templates have no Wait.

## CT. Custom workflow catalog

**Decision.** Six triggers, about 25 condition fields, one level of condition groups and five new actions, so shop owners can build workflows the templates don't cover.

**Why.**
- **D1 Scope.** This list only. HTTP and webhooks, Slack, tags, a stock trigger, product actions, a refund action, Change workflow status and YayMatik triggers stay out: each needs its own design, and connectors belong to v1.2.
- **D3 One customer stat, paid statuses only.** Total spent and paid order count use `wc_get_is_paid_statuses()`; a person is a user id plus a billing email, guests by billing email. Total spent subtracts refunds, as a refund is money the store gave back. The query runs only when a rule reads it, because it can scan an unindexed column on posts stores.
- **The existing `customer.paid_order_count` stays.** It already meant the same person and the same statuses, so a second `order_count` field would be two names for one fact. Its key, and so the templates, don't change; `CustomerStats` now computes it.
- **D4 Order paid fires once, on a real unpaid → paid change.** The claim sits in order meta, like Order created, so a status that goes back and forth doesn't start it twice.
- **D5 Milestones cross a threshold.** Customer order count reaches and Customer total spend reaches fire on Order paid only when this order crosses the number. The run's subject stays the order, and the claim is per person (`user:<id>`, or a hash of the guest's email), so one person gets one start per workflow. The hash keeps a plain email out of the claims table and fits its column.
- **D7 Review posted.** Approved product reviews only, top level only, claimed per review. A guest's customer is their email alone, never an account found by email: anyone can type someone else's email into a review form.
- **D8 Change customer role is guarded at run time.** Write actions run as user 0, so the checks can't lean on the current user. It never assigns or touches a role with a protected capability, and never removes a person's last role. Protected means anything beyond a customer's `read`: staff and site capabilities, and also posting, uploading, moderating, product and coupon editing, reports, import and export. Triggers fire on public events (an order, a review), so any buyer could be given the role. A filter opens it for stores that want it. Everything is checked again at run time, in order: no account, a protected user (super admin, staff role, or a protected capability by role or given directly), the role gone or made protected since the save, the last role. Replace adds the new role before dropping the others, so the account never has none. Run history keeps role names only, which are labels, not customer data.
- **D10 Groups, one level.** `match` + `rules` stay, and a rule may be `{ match, rules }`. Old configs and templates stay valid, and one level covers "A and (B or C)" without a tree editor. It's also how a rule says "not in the last 30 days, or never": no date matches neither relative comparison, so a group adds `is_not_set`.
- **D11 Customer custom field.** An action and a `customer.meta` condition together: one writes what the other reads. The key input suggests keys other workflows use, so spelling stays the same.
- **Write actions keep runs free of values.** Update order custom field outputs the key and whether the value changed, never the value: a rendered value could hold a name or an email, and runs keep refs only. Old → new goes in a private order note, which lives and goes with the order.
- **A customer field write asks the database, not only the key's spelling.** `update_user_meta()` finds its row with the column's collation, which ignores case and, on many sites, accents. A key that reads harmless in PHP could then update `wp_capabilities` and make the customer an admin. So before writing, the step reads which stored keys the database matches to it, and refuses if any is protected; a failed read refuses too. Update customer custom field writes no note: there is no order to hold one, and runs keep no values.
- **Resend WooCommerce email offers an allowlist.** Only customer emails whose `trigger()` takes just the order id: others take a fulfillment, a template, a note or a user, and calling them with an order id fails or sends the wrong email. A plugin adds its own through `yaymatik_resendable_emails`; one with another signature fails that step, not the run's process. An id this WooCommerce doesn't have (the review request is behind a feature) isn't offered. WooCommerce's `trigger()` reports nothing, so the step checks that the email is on and the order has a billing email first. Run history keeps the email's id, never the address. The refunded email is left out, as a resend would always use the full-refund text.
- **D15 Wait until.** It lives in the YayMatik module, which owns flow logic. Its time is any minute, `HH:MM`, typed in a time input: a 96-item list was too long, 15-minute steps confused merchants, and WP-Cron's delay is the same for any minute. A config from the earlier hour select isn't migrated: nothing has shipped, so it reads as a setup issue.
- **D16 Stop waiting runs: dropped from v1.0.** It moves to the v1.1 backlog. `PersonSubjects` stays: the personal data tools read it.

**Cost.**
- Order paid can't see the time before it was listening: an order paid before its workflow was active, then unpaid and paid again, starts it once. An order saved already paid in its first save (imports, `wc_create_order()` with a paid status) never starts it.
- Milestones: two paid orders of one person in one request read the same totals, so a threshold between them can be missed (bulk edits). A guest who later makes an account is the same person in the totals but has two claim keys, so a threshold can start twice if refunds took them back below it.
- Customer totals sum currencies as stored, and scan the unindexed `_billing_email` on posts stores, only when a rule or a milestone reads them.
- `refund.is_full` reads amounts only: a filter that changes WooCommerce's own partial-or-full decision isn't followed.
- The active-trigger list: if a writer waits more than 5 seconds for its lock, a trigger can be left out until the next change or the daily recompute. A process that started before an activation (a long Action Scheduler batch) misses that trigger's events until it ends.
- A review's guest customer has only an email.
- Resend WooCommerce email can't resend the refunded email.
- Wait until's time in a DST gap runs at the shifted time.
- Update customer custom field writes one text value, never a list. A key that differs from a stored one only in case reads as changed on every run.

## PI. Privacy lookup index

**Decision.** A `run_emails` table holds each email and guest claim key a run mentions, written by `RunStore` and `RunStepStore` at every write of a run's scope or error or a step's input, output or error. Privacy export and erase look up a person's runs there, and by `runs.subject` with `KEY subject (subject, status)`. They no longer scan every run and step.

**Why.**
- The old lookup was a LIKE / REGEXP over every run and every step's JSON, on every export or erase page. At 200k runs it took about 5.5 s per page; the index takes about 4 ms (plan 033 bench).
- Plain normalized emails, not an HMAC. A hash needs a secret, and a lost or changed secret would make erase quietly find nothing. The rows hold what the runs already hold, and erase removes them with the run's data.
- The extractor matches what erase replaces (`replace_json()`, `replace_text()`): a whole JSON string, and in text the same boundaries. So the index never finds less than erase would scrub. `check-privacy-index` compares it with the old scan.
- Index rows are written with INSERT IGNORE in one statement per write. Writes to an existing row select its run from `runs` or `run_steps`, so a run that isn't there gets no row; `create()` indexes the id it just inserted.
- An indexing failure is logged and the run goes on: a privacy lookup is less important than the customer's email going out. `check-privacy-index` catches a gap.

**Rejected.**
- Keep the scan and add LIMITs: still a full read of the steps table per page.
- MySQL full-text or JSON indexes: neither matches the erase boundaries, and both differ between MySQL and MariaDB.

**Cost.**
- About 3 ms more per run with 5 steps whose values all hold an email (bench: 8.9 vs 5.6 ms).
- Values are cut to 190 characters, so two addresses that share their first 190 characters find each other's runs. Erase still only replaces the exact address, and keeps the index link of an address that long.
- An overwritten value leaves its row until an erase finds the run and removes it.
- No backfill: before v1.0, dev data is reset (`./run.sh reset-data`) after deploy.

