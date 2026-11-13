# Workflow Core — design and decisions

**Status:** F1 backend built (storage §2, runtime §3, data layer §4, step contract §5, templates §6, modules §8); the rest is planned · **Decisions confirmed:** 2026-10-07 · **Roadmap:** [ROADMAP-dev.md](ROADMAP-dev.md) · **Product scope:** [ROADMAP-v1.0.md](ROADMAP-v1.0.md)

"Workflow Core" is the code that stores, checks and runs workflows. It is not called "engine": in this repo `includes/Engine/` means WP/WC glue. This doc holds the decisions and the interfaces; the reasons behind them are in [workflow-core-decisions.md](workflow-core-decisions.md). The roadmap refers to sections here as `design §N`.

## §1 Naming and layout · C1, C2

- `includes/Engine/` keeps only WP/WC glue: `BEPages/`, `API/`, `Register/`. REST controllers call Workflow Core and hold no workflow logic.
- Workflow Core moves to `includes/Workflow/`, namespace `YayMatik\Workflow\`. The top level holds what modules register; `Core/` holds the shared code every module uses. `includes/Engine/` = WordPress glue (REST, admin pages, scripts). A folder is named for the plural of the base class it holds; namespaces follow folders.

| Folder | Holds |
|---|---|
| `ModuleRegistry.php`, `Modules/` | The modules and what they register (§8); planned: connectors |
| `Triggers/`, `Conditions/`, `Actions/` | One class per step; Wait and Wait until (actions, YayMatik module); planned: control steps (For each, Switch, Call workflow, Set variable, Stop) |
| `DataItems/` | Data items (order, cart, customer, refund, review, site, order_item) |
| `Core/Steps/` | The step contract (`Step`, `Trigger`, `Condition`, `Action`, `Field`, `StepResult`, `StepIcons`, `StepRegistry`) |
| `Core/Data/` | `DataItem`, `DataRegistry`, `Type`, `Operators`, `Ref`, `RefResolver`, `Scope`, `Template`, `Filters` |
| `Core/Definition/` | Graph schema, `Validator`, `Catalog` (steps, fields, outputs, data types → admin app and AI) |
| `Core/Runtime/` | `Dispatcher`, `Executor`, `Worker`, `Runtime`, `Cancellation`, `PersonSubjects`; planned: `Scheduler`, `Retention` |
| `Core/Storage/` | Tables, upgrades, `WorkflowStore`, `VersionStore`, `RunStore` |
| `Core/Email/`, `Core/Coupon/` | Email rendering and unsubscribe; coupon writing |
| `AI/` | Provider interface and AI features (v2.0) |

`includes/Pro/Workflow/` (namespace `YayMatik\Pro\Workflow\`) copies the top level once a Pro file needs a folder; the free zip leaves it out.

## §2 Storage · C3, C8, C10

Custom tables replace the CPT and post meta. One installer, `Storage/Schema::maybe_install()`, creates and upgrades them with `dbDelta` + the `yaymatik_db_version` option. `trigger` and `cursor` are MySQL reserved words, so those columns are `trigger_id` and `position`.

Table names below omit the prefix: each is `{prefix}yaymatik_<name>`, e.g. `wp_yaymatik_runs`.

| Table | Columns (main) |
|---|---|
| `workflows` | id, name, status, trigger_id, live_version_id, draft_version_id, activated_at, created_at, updated_at · index (status, trigger_id) |
| `workflow_versions` | id, workflow_id, action, graph (logic), layout (step positions), user_id, created_at, updated_at. A version's logic never changes; its layout may. Only the draft row's logic is edited in place |
| `runs` | id, workflow_id, version_id, trigger_id, subject (`order:<id>`, `cart:<id>`, `user:<id>`, `review:<id>`), status, position, scope, wait_until, error, parent_run_id, root_run_id, depth, started_at, finished_at · index (subject, status) |
| `run_steps` | id, run_id, step_id, iteration, status, input, output, error, attempt, started_at, finished_at |
| `carts` | id, session_key (WooCommerce session: user id or guest `t_…`; unique), user_id, email, first_name, items (JSON lines), total, currency, status (active, abandoned, ordered), abandoned_at, abandon_trigger, series_at (the abandon that started the cycle's reminder series, its trigger, and when; set together when a workflow takes the cart), checkout_at, order_id, restored_at, last_activity_at, created_at, updated_at |
| `trigger_claims` | id, workflow_id, subject, claimed_at · unique (workflow_id, subject). One row per workflow and claim key, for triggers that start once per key: the key is a subject (`order:12`), `user:<id>`, or a guest's hashed email (`email:` + sha256 of the lowercased email, 70 characters, so no plain email is kept and it fits the column); a deleted workflow's rows go in Cleanup |
| `run_emails` | run_id, email · primary (run_id, email), index (email, run_id). The emails and guest claim keys (`email:…`) a run's scope or error or its steps' input, output or error hold, lowercased and cut to 190 characters. `RunStore` and `RunStepStore` add them at every write of those columns (`RunStore::index_emails()`, INSERT IGNORE); a failure is logged and the write stands. Privacy export and erase find a person's runs here and by `runs.subject`; erase removes the person's rows, and deleting runs removes theirs |

- A run pins the version it started on.
- Deleting a workflow deletes its versions; its runs stay until retention cleanup (M7.4).
- Why tables, not CPT: an indexed lookup on every event instead of a `meta_query`, no JSON slashing, atomic writes, simple cleanup.
- Tables replace the CPT before v1.0, while no merchant has data: dev data is reset (`./run.sh reset-data`), no migration.

## §3 Runtime · C4, C5, C9, C11, P3

**Run states:** queued → running → waiting → completed / failed / cancelled / skipped.

- **Dispatcher.** Keeps today's flow: collect events during the request, match at shutdown. It now creates a `runs` row per matching workflow and queues it. It reads each trigger's active workflows once per `flush()`.
- **Active triggers.** The Dispatcher listens only to triggers with an active workflow, read from the autoloaded option `yaymatik_active_triggers`, so a store's other events cost nothing. A reader with no option computes the list and stores it with `INSERT IGNORE`, never overwriting a writer. Activate, Deactivate, Apply and Delete rewrite it after their commit under one lock (`WorkflowStore::refresh_active_triggers()`), then fire `yaymatik_active_triggers_changed`, and the Dispatcher adds the listeners it lacks. If the lock isn't taken in 5 seconds or a query fails, the write logs and deletes the option, and the next reader rebuilds it. Cleanup recomputes it daily. A read that fails isn't stored, and that request listens to all triggers. Known limits: past the lock wait, a slow writer or an earlier reader can leave a trigger out until the next change or the daily recompute. A process that started before an activation (a long Action Scheduler batch) misses that trigger's events until it ends.
- **Worker.** Action Scheduler is the clock and the worker; the `runs` table is the source of truth. A job takes a row lock (`UPDATE … WHERE status IN (…)`) so two workers never run the same run.
- **Slices.** A job keeps running steps until the run finishes, hits a Wait, uses ~20 s or its memory budget, or ends a loop batch. It then saves its position (the `position` column) and queues the next slice.
- **Wait until.** Like Wait, it ends the slice with `StepResult::wait( $until, $outputs )`; its `until` output (store time, `Y-m-d H:i`) stays readable by later steps. Its `time` is `HH:MM`, any minute (00:00–23:59), a text field in PHP drawn as a time input by the step's own form; `WaitUntil::validate()` and the editor check it with one message. The field is blank by default, so a config without a valid time is a setup issue, and the editor starts new steps at 09:00. The card and the hint show it in the site's time format. `WaitUntil::next_time()` finds the next chosen time on a chosen weekday in `wp_timezone()`, stepping by calendar day, so the wall-clock time holds across DST: a time in a spring gap runs at the shifted time, a time that occurs twice takes the first.
- **Errors.** Each step has a policy: stop the run (default) or continue. Retries come in v1.1.
- **Loop safety.** `parent_run_id`, `root_run_id`, `depth` replace the in-memory chain. A workflow never starts again inside its own chain; max depth 5; a max loop size. Rate limits per workflow, per 60 s, pause (turn off) a runaway workflow: 100 runs started by other runs (a loop always has a parent), or 1000 runs in all. Runs from plain events only count toward the 1000, so a bulk edit of hundreds of orders never pauses a workflow.
- **Scheduled and query triggers.** A schedule fans out to one run per matching subject, in batches. A trigger opts in with `Trigger::claims_per_workflow()`: the Dispatcher then claims the workflow and subject in `trigger_claims` before starting the run, so each workflow starts once per subject (the claim has no end date). When the run doesn't start (`start_run()` returns 0 or throws: rate-limit pause, loop guards, no live version, a failed write) it releases the claim, and so does the Worker when a run with no step rows ends because the workflow was turned off, so the next check retries. This generalises the `_yaymatik_order_created` order meta in `OrderCreated::claim()`. A trigger may claim by another key than its subject (`Trigger::claim_key()`, default the subject); its refs then hold `claim_key`, and the Worker releases `scope.trigger.claim_key` when there is one, else the subject.
- **Inactive sweep.** Daily (`yaymatik_inactive_sweep`, group `yaymatik`, `Runtime/InactiveSweep`; `Cleanup::ensure()` keeps it scheduled). For each distinct days value among active Customer inactive workflows it queues a job (`yaymatik_inactive_sweep_batch`, args days and page) that reads the orders N to N+7 days old (so a late job or a failed start gets another try) in pages of 200; a full page queues the next 2 minutes later, and turning off the last workflow on that value ends the chain. Only real orders count: every status except pending, failed, cancelled and checkout draft. An order fires `yaymatik_customer_inactive` only if it's the person's latest real order, by billing email and by account (same second: the higher id); anonymized orders and orders with no billing email are skipped. Each batch flushes the Dispatcher itself, as one `wp action-scheduler run` process has one shutdown for many batches. The claim is per workflow and order, so a person's newer order N days later starts again. Nothing active: only the workflows read.
- **Abandon sweep.** Every 5 minutes (`yaymatik_cart_sweep`, group `yaymatik`, `Cart/CartSweep`; `Cleanup::ensure()` keeps it scheduled) the job marks `abandoned` every active cart with an email and items whose last activity is past `abandon_minutes`, up to 24 h further back. At most 200 carts per sweep: its events start runs at one shutdown, and a larger backlog could reach the 1000-run limit and pause a workflow. It does nothing while cart tracking is off. Each abandon fires `yaymatik_cart_abandoned( $cart_id, $kind )`, `$kind` being `cart_abandoned` or `checkout_abandoned` at that abandon; each cart trigger takes its own kind. The cart trigger's claim starts the series with `CartStore::start_series()`, one UPDATE on `series_at IS NULL`, so an abandon no active workflow takes locks nothing and a cart gets at most one series per cycle. Only a new cycle (`CartStore::reset_cycle`) clears it, so no claims table is needed. Activity turns the row active again and keeps the series. A claim the cart trigger refuses with a reason (`Trigger::skip_reason()`: the cart already has a series, the cart was ordered, the shopper unsubscribed, or the email had a series in the last 7 days) records a finished `skipped` run, with the reason as its error, for each active workflow of that trigger (`Dispatcher::skip_runs()`). It shows under Skipped and counts in the overview's runs and toward the rate ceiling.
- **Cancel.** Deactivate, Update (Apply) and the rate-limit pause cancel waiting runs (v1.0 rule) in one place, `Cancellation::waiting()`, under the workflow lock (`WorkflowStore::locked()`, shared by REST and the rate-limit pause; a pause that can't take it is logged, and the workflow stays paused for the rest of the request): it selects the waiting runs, cancels those still waiting in one UPDATE, then marks their waiting step rows `cancelled`. Deactivate and Apply return the count (`cancelledRuns`). The runs' Action Scheduler jobs stay; when one fires it can't take the run and does nothing. Apply leaves queued and running runs on their pinned version.
- **Stop waiting runs.** Dropped from v1.0; it is in the v1.1 backlog.
- **Stop right away.** Before each step, and after saving a wait, the Executor reads the workflow's status fresh (not from the object cache); once it is off, the run ends `cancelled` ("The workflow was deactivated."). A run that ends this way, or before its first step, closes its open step rows (`running`, `waiting`).
- **Cleanup.** A daily Action Scheduler job (`yaymatik_cleanup`, group `yaymatik`, `Runtime/Cleanup`) deletes carts last active more than `cart_retention_days` ago, then the `trigger_claims` rows of deleted workflows, then finished runs with their steps (deleted workflows' runs included) that finished more than `run_retention_days` ago. Carts go first: they hold emails, and the privacy text promises their deletion. Batches of 500 within ~20 s; what's left goes the next day. Queued, running and waiting runs are never deleted, nor any run of a chain (`root_run_id`) that still has one: the loop guard walks `parent_run_id`. `Cleanup::ensure()` on `action_scheduler_init` schedules the job when it's missing, checked at most hourly through the autoloaded option `yaymatik_cleanup_checked_at` (Unix time).
- **Stop on order.** A cart run stops as soon as its shopper orders or the cart is gone. At once: paying an order (by its own cart or by email), emptying a cart and cleanup cancel the cart's waiting runs (`Cancellation::carts()`). For a queued or running run: `Trigger::stop_reason()`, read before the first step (`Worker`), before each step and after saving a wait (`Executor`). Such a run ends `cancelled` with "The shopper placed order #N." or "The cart was emptied or deleted." A shopper who returns and leaves again stays in the series.
- **Stop on unsubscribe.** Unsubscribing cancels the waiting runs of that email's carts at once; `stop_reason()` stops queued and running ones ("The shopper unsubscribed from these emails."), so no coupon is made for an email that would be skipped.
- **Order paid.** `OrderPaidTrigger` listens to `woocommerce_order_status_changed` and keeps changes from a status outside `wc_get_is_paid_statuses()` to one inside it; at shutdown the order must still be paid. A new order has no stored status, so WooCommerce reports its first save as a change from pending: an order whose first save already has a paid status (an import, `wc_create_order()` with a paid status) is caught at `woocommerce_before_order_object_save` while it has no id, given its id at `woocommerce_new_order`, and its change is dropped. `woocommerce_new_order` alone can't tell, as it also fires when a draft is placed. Orders created in the admin (an auto-draft first), checkout drafts, gateways and REST `set_paid` do start it. `OrderPaid` starts once per order through the `_yaymatik_order_paid` order meta, like `OrderCreated`; an order saved already paid gets it at once, so it never starts later. Its runs act on the order that started them, so it never warns about refiring (`fires_for_own_runs()` false). Known limit: the meta is only written while an Order paid workflow is active, so an order paid before that, made unpaid and paid again, starts it once.
- **Milestones.** Customer order count reaches and Customer total spend reaches (`CustomerMilestone`, on `OrderPaidTrigger`) read the person's paid totals after this order once per event (`CustomerStats::totals()`), take this order's share off (one order; its total minus its refunds) and start only when this order crosses the number: before < N ≤ after. The run is about the order; the claim is per person: `user:<id>`, else `TriggerClaimStore::email_key()` of the billing email. No account and no email: no run. A personal data export lists a guest's claims ("Customer milestones"); an erase deletes them and the key in that person's runs; `user:` claims stay. Known limits: two paid orders of one person in one request read the same totals, so a number between them can be missed (a bulk edit, or one Action Scheduler batch); an order saved already paid (an import) counts in the totals but starts nothing, so a number it crosses is skipped; a guest who later makes an account is counted as one person but has two claim keys, so a number can start twice only if refunds took them back below it.
- **Review posted.** Listens to `wp_insert_comment` (approved at once) and `transition_comment_status` (approved later) for top-level product reviews; at shutdown the review must still be approved. It claims per workflow and review, so unapproving and approving again doesn't start it twice.
- **Order note added.** Listens to `woocommerce_order_note_added`. A note WooCommerce adds for a status change carries no mark of who caused it, so a note counts as added by a workflow when a run was being run as it was added (`Worker::current()`, stored as `by_run` in the event). "Skip notes added by YayMatik workflows" (on by default) drops those; turned off, the trigger reports `fires_for_own_runs()` and the editor warns on actions that add notes, while the loop guard still stops a workflow restarting itself.
- **Planned (CT).** More triggers, see [ROADMAP-dev](ROADMAP-dev.md).
- **Known limit.** Action Scheduler fails a job still running after its timeout (~5 min). If a step is still running then, a recovery job can take the run while the old worker is alive. Slices stay far below that (~20 s), so only a single very slow step can hit it.

## §4 Data layer · C6, C7

- **Types:** string, number, boolean, date, money, enum, entity\<T\>, list\<T\>.
- **Condition types** pick a rule's comparisons (`Operators::BY_TYPE`): number, string, enum, date, boolean, list, meta. List compares with `matches_any`, `matches_all`, `matches_none`, lowercased and trimmed; an empty list matches none only. An enum adds `is_any_of`. A date adds `in_last` / `not_in_last` (N hours or days, fixed lengths like Wait) and `is_set` / `is_not_set`. No date matches neither relative comparison. A date field's optional `timestamp` is the moment behind it; without one it counts as 00:00 store time on its date. Meta compares as numbers when both sides are numeric, else as text; a number comparison with a non-numeric side is false.
- **Entity types** with relations: order (`customer`, `items`), order_item (`product`), customer, refund, review, product, coupon, cart, site.
- **Refund entity.** Order refunded supplies `refund` (`refund:<id>`, a `WC_Order_Refund`) beside the order; its run's subject is the order. `refund.is_full` reads the refunds up to this one against the order total, so a later refund never changes an earlier one's answer. Known limits: amounts only, so a filter that changes WooCommerce's own partial-or-full decision isn't followed, nor its rule that an order with a free item not yet refunded is partly refunded; it's read when used, so deleting an earlier refund before a Wait ends changes a later one's answer.
- **Scope** paths: `trigger.*` (with `order` / `customer` shortcuts), `steps.<id>.*`, `loop.item` / `loop.index`, `run.*`, `site.*`.
- **Refs, loaded lazily.** Entities are stored as `{type: 'order', id: 12}` and loaded on demand, cached per slice. Facts only true at trigger time (status from / to, cart contents, event payload) are snapshotted. Data is fresh after a long Wait, and rows stay small.
- **Review entity.** Review posted supplies `review` (`review:<id>`, a `WP_Comment` of type `review` with no parent) and its customer (`customer:review:<id>`, through `RefResolver::reviewer()`): the account when the review has a `user_id`, else `Core/Data/EmailCustomer`, the email typed and nothing else, never an account found by that email (anyone can type someone else's). A review run's subject is `review:<id>`; run history names it "Review #N" and links the comment's edit screen while it exists. Rating and "verified" are read at shutdown, as WooCommerce saves them after `wp_insert_comment`. Personal data lookups find `review:` runs by the author email and by the account that has the email. Known limit: a guest reviewer has only an email.
- **Cart entity.** `cart` reads a saved row (`Cart/SavedCart`). A cart run's customer is `Cart/CartCustomer` (`cart:<id>`): email and first name from the row, the rest from the row's own account, never an account found by email. A cart run's subject is `cart:<id>`.
- **Account customer.** A customer from an account (`user:<id>`, the subject of Customer account created) is a `WC_Customer` through `RefResolver::account()`. Billing details are empty until a first checkout, so the account's email and first name fill them in memory, never saved; billing values already there win. The label of such a run is the email `customer.email` reads, and the link goes to the user's edit page. Personal data lookups find `user:` runs by the account email and by the `billing_email` meta.
- **Gone entity.** If an order or customer was deleted, the step is skipped and run history says why: "Order no longer exists." or "Refund no longer exists.", and for an account "The customer was deleted."; once a queued run's subject is gone, the run ends Skipped with the same text.
- **Customer stats.** `Core/Data/CustomerStats` is the query behind `customer.paid_order_count`, `total_spent`, `last_order_date` and `purchased_product_ids`: paid statuses (`wc_get_is_paid_statuses()`), an account plus a billing email, on either order store, with refunds subtracted and currencies summed as stored. It runs only when something reads those fields, once per customer object; a slice loads a new object, so numbers are fresh after a Wait. Known limit: a step that pays an order and a later step in the same slice with no Wait between read the same numbers. On a posts store it scans the unindexed `_billing_email`.
- **Search lists.** A list field with `search` (`product`, `category`, `coupon`) gets its choices from `GET /search/products|categories|coupons` (20 a page), never from `yayMatikMeta`. It's a prop on the data field, not a step `Field`. Products are parents, so a variation matches a rule on its product. A rule's summary never calls REST: it reads "3 selected"; the form loads names through `include[]`, and a deleted id shows `#12`.
- **Custom fields in rules.** `order.meta` and `customer.meta` compare a custom field the rule names, so they have `merge_tag` false: not in "Insert variable", not a known tag. `MetaKeys` refuses WooCommerce's own order fields, keys it hides, and the user meta that holds access or sessions, on read as well as when checking a rule.
- **Trigger facts.** `Trigger::facts()` declares plain values true only at event time (another plugin's `facts`), stored in the run's `trigger` section and read as `trigger.<key>`: listed first in "Insert variable", accepted by the Validator for that trigger only, comparable in a condition (`DataRegistry::field( 'trigger.<key>' )`, the first trigger with the key defines it), shown on the trigger line of run detail (`RunPresenter`), and in previews from `sample_facts()`.
- **Other plugins' data.** Their data fields and data items reach core as field definitions through `Workflow\Extension\FieldAdapter`: every callback runs under `Guard`, `customer` and `cart` callbacks get `CustomerView` / `CartView` (`CustomerViewAdapter`, `CartViewAdapter`) instead of the internal object, and display HTML is kses'd. Fields on a core item come through `yaymatik_data_item_{id}_fields`; a module's own item (`ExtensionDataItem`) loads through `RefResolver::default_loaders()`.
- **Available variables per step.** Each step declares outputs. The Validator walks the steps above each step to list what it can use. "Insert variable" and the template checks use the same list.

## §5 Step contract · L2

**PHP is the one source of truth for a step's settings.** The editor builds its checks from it, so PHP and TS can't disagree.

This contract is internal. Other plugins use the public Extension API ([extension-api.md](extension-api.md)): args arrays and callables that core wraps in its own step classes (`Workflow\Extension\ExtensionTrigger`, `ExtensionAction`; a throw in a callback is caught there by `Guard`, recorded as a diagnostic, and never reaches core).

```
Step:   id() label() plugin() module()
        icon(): string           a name from core's step icon list, e.g. "mail" (L5)
        fields(): Field[]        settings, rules, widgets
        validate( config )       optional extra checks the field rules can't express
        context_error( config, available )   checks that need the data items the step can read there (Validator and Executor); null when fine
        setup_issue(): ?string   why the step can't run on this site; blocks Activate (issue `setup_issue`; core's cart triggers keep `cart_tracking_off`)
        outputs(): array         what later steps can use: key => [ label, type, options?, display?, block? ]
        sample_outputs( config ) made-up outputs for previews of later steps (Generate coupon → YMSAMPLE)
        summary(): string        card text, one short line, e.g. "Send to %recipients%"
        run( Scope, config ): StepResult   ok( outputs ) | skipped( reason ) | failed( error )
Action: emits(): string[]        trigger ids it can set off
Trigger: fires_for_own_runs( config )   whether its workflow reacts to events its own runs cause
```

- **Field vocabulary** (kept small; a rule value's search list is a data field's `search`, see §4, not a word here): text, textarea, html (rich text, cleaned with `wp_kses_post`; drawn as WordPress's classic editor, Visual / Code tabs, stored as WordPress saves it: paragraphs as blank lines or `<p>`, `wpautop` puts them back), emails, select, multiselect, toggle, number, number_unit, notice, rules (core only). Modifiers: `required`, `variables`, `placeholder`, `help`, `show_if`, `suggest` (names under a text input that the app loads, e.g. `customer-meta-keys`: custom field names other workflows read or write; any other text can be typed). A form that needs more gets a custom panel, not a new word.
- **Catalog.** Steps, fields, outputs and data types go to the admin app through `yayMatikMeta` (and to the AI in v2.0).
- **Picker sections.** `Action::category()` and `Trigger::category()` put a step under a heading in its plugin's list (Email, Order, Carts, Customers, Reviews). A trigger's is optional: one without a category has no heading. Labels come from the catalog (`automation.categories`), so another plugin's own categories show with theirs.
- **Warnings.** `Validator::warnings()` lists what never blocks Activate: `may_refire`, an action whose `emits()` holds its workflow's trigger, when the trigger's `fires_for_own_runs()` is true. REST sends `warnings: { live, draft }` with every workflow; the step's panel shows the line from the last save.
- **Editor checks.** Automation builds valibot rules from the PHP fields. Validation always runs in Automation, never in a panel. Rules only in PHP `validate()` show up as the step's issue after a save (under its field when the form draws it, or under its rule for a condition, else at the top of the panel; "Needs setup" on the card), and still block Activate.
- **Forms.**
  - No custom form → the generic `StepForm` draws one from the fields. A simple step from a Yay plugin needs PHP only.
  - Core step with a complex form (Condition rules) → `defineStep( { id, Form } )`, imported directly. No global call, fully typed.
  - Plugin step with a custom form → an iframe editor through the public Extension API ([extension-api.md](extension-api.md) §5.6, 1.1).
  - A custom `Form` gets `{ config, onChange }` (`StepFormProps`).
  - Beside any form, `defineStep` takes `Footer` (under the form, with its latest config, e.g. Send email's unsubscribe note) and `Actions` (panel header, e.g. Send email's Preview / Send test). Send email and Generate coupon use the generic form plus these.
- **Icons (L5).** A step's `icon()` is a name from a fixed list in core (lucide icons, about 40), never an SVG. The editor draws it in the step's tile, tinted by kind (trigger, condition, action), on the canvas, in the picker, the panel header, template cards and the preview. A missing icon is added to core's list, not shipped by the plugin.
- The `rules` field type is ours: its checks are written in PHP (`Criteria`) and TS. Plugins never write validation in JS. Which custom field names are protected only PHP knows, so that one shows after a save: the issue carries the rule's place (`rule`: `[item]`, or `[group, rule]` inside a group) and the form draws it under that rule; an issue with no place shows at the top of the panel. A comparison of another shape clears the value (`withOperator`); another field keeps it only if it still fits (`ruleForField`).
- **Custom field writes.** Update order / customer custom field refuse a protected key (`MetaKeys`) at save and again at run time, as a stored config may never have passed the panel. A customer write also asks the database which stored keys match (`MetaKeys::user_collides()`), since the `meta_key` collation can match `WP_CAPABILITIES` or `wp_capabilitiés` to `wp_capabilities`. A customer without an account is skipped.
- **Condition groups.** `rules` is usually a flat list; an item may also be a group, `{ match, rules }`, one level deep: `sanitize` drops a group inside a group, and a flat old rule comes out unchanged, so old drafts don't read as edited. Top-level items join with the condition's `match`, a group's rules with its own. An empty group is a `groupRulesRequired` issue ("Add a rule, or remove this group.", "Needs setup") until it's removed. The summary reads "(A or B) and C".
- **Output display.** An output may have its own display text, built from the step's other outputs when read and never stored (`display`), e.g. a coupon's expiry in the site's date format. `block: true` marks a ready-made email block (the coupon box). `Scope::set_output_display()`; the Executor and Preview wire it from the graph.

## §6 Templates · L3

Our own small template module. **No Liquid or other template library, now or later.**

- **Tier 1 (v1.0):** `{{ path | filter: arg }}`, e.g. `{{ order.customer.first_name | default: "there" }}`.
  - Filter registry: `default`, `date`, `money`, `upper`, `lower`, `join`. A filter never fails: input it can't use comes back unchanged. Yay plugins may add filters later.
  - The editor checks paths and filters against the step's available variables (§4).
  - A missing value prints empty; the step never fails for it.
  - Output is escaped in HTML. No `raw` filter for users.
  - **One renderer, in PHP.** TS only parses, for checks and autocomplete. Preview and Send test call the server.
  - Today's `{{ item.field }}` tags keep working.
  - **HTML messages** (`html` fields): the stored HTML stays as written; each value is escaped for where it lands: `esc_html` in text, `esc_url` over a whole `href` / `src` (a value after the start of the URL is `rawurlencode`d first), `esc_attr` in other attributes, dropped inside a tag. A block value alone in a paragraph replaces it. Then `wpautop`. Plain text is what the editor saves for a message without tags (`"Hi\n\nTom &amp; Jerry"`): it's read as plain text. `Template::render_html()`, `Email\EmailBody`.
  - Subjects and headings are plain text on one line: typed HTML tags removed, values never stripped.
- **Tier 2 (on demand):** lists come from server-rendered email blocks (product grid, cart items, coupon box, review rows; all exist). A product grid is a data field marked `block` (`order.cross_sell_products`, `order.upsell_products`, `Email\ProductGrid`): up to 4 published, visible, in-stock suggestions not already in the order, 2 per row (an odd last one centered), each image scaled into a square of up to 160px (narrower with the column on phones), names cut at 60 characters, as wide and spaced as the coupon box; none → empty, so the block is left out. With sample data it shows the store's 4 newest products. "Insert variable" offers it under Blocks, in a section named after its data item, in rich text fields only (never in a subject, heading, To or condition). `cart.items` (`Email\CartItems`) shows the price, line total, total and currency the shopper saw, with the product's live name, image and link; a gone product shows its saved name, a placeholder image and no link. `cart.restore_button` is a link in a table, in the store's email color; with an inert cart (`SavedCart::inert()`) it goes to `#`. `order.review_products` (`Email\ReviewProducts`) has a row per product the customer hasn't reviewed, read when the step runs: up to 6 published parents open for reviews, no approved or held review by the order's billing email or user; each row has the image, the name and a small "Leave a review" button to the product's `#reviews`. With sample data it shows the store's newest products. Site blocks need no trigger data: `site.welcome_links` (account and shop buttons side by side), `site.shop_button`, `site.account_button`, and `site.new_products`, the store's 4 newest visible products as a grid; Preview reads them live, as a run does. Every button is `Email\EmailButton`: a link in a table, in the store's email color. Conditional text comes from a "Show if" setting on a block, using the Condition rules builder.
- **Escape valve (only if users ask):** `{% if path %}…{% else %}…{% endif %}`, truthy check only.

**Customer emails** (Send email):
- WooCommerce sender and email template, styles inlined by WooCommerce's mailer. One To address; the final address (after variables) decides.
- Every customer email has an "Unsubscribe" link under the message and `List-Unsubscribe` / `List-Unsubscribe-Post` (one-click) headers. The link carries base64url(email) and an HMAC of it (own secret, no expiry). Unsubscribes are per address, for all YayMatik customer emails; the step is skipped ("The customer unsubscribed from these emails." in run history) and the run goes on. Staff email ignores them.
- **Preview / Send test** render only, from the editor's unsaved step and graph: the latest paid order (its number shown), for a cart trigger the newest cart with its restore link going nowhere (`#`), or made-up data (`DataItem::sample()`, flagged for the "Sample data" badge); steps above give sample outputs. The unsubscribe link goes nowhere (`#`) and there are no List-Unsubscribe headers. A test goes to the logged-in admin, subject prefixed `[Test]`. A step with issues is refused (400 with `issues`), listed in the panel.

## §7 Graph · P7

- One parent per step. Condition branches `then` / `otherwise`; Switch has N handles; For each has `body` and `done`.
- **Branches never merge.** Shared steps go in a separate workflow started by Call workflow.
- A version's `graph` holds the logic (`schemaVersion`, steps without positions, links); its `layout` holds step positions by step id. REST and the editor get one graph with `position` on each step. Logic alone decides drafts and versions: moving a card rewrites only the layout of the row being edited (`WorkflowStore::save_layout()`), and Activate / Deactivate copy the layout to the new version.

## §8 Modules and the Yay ecosystem · P1, P2

- The runtime is **100% free**: executor, Wait, loops, Call workflow, scheduled triggers, run history.
- Steps and data items belong to **modules**, one per plugin (`ModuleRegistry`, `Modules/`). A module lists its triggers, conditions, actions and data items by class name; the catalog sends its id as each step's `plugin`.
  - **Free vs Pro is the folder.** `includes/Pro/` holds Pro modules that extend the free ones; the free zip leaves it out, and the registry loads the Pro modules only when their classes exist.
  - An unfinished step is not registered, so it stays out of a release. No tier checks inside steps.
  - Other plugins register on `yaymatik_register` (fired once, when the registry is first built) and start their triggers with `do_action( 'yaymatik_fire', $trigger_id, $payload )`, handled by `Workflow\Extension\Fire` through `Dispatcher::collect()`. The filter `yaymatik_modules` (module classes, before `init`) is internal, for Pro. A taken id (module, step, data item) keeps its first owner and the second claim is a diagnostic.
  - Filters for extending core's steps and data: `yaymatik_data_item_{id}_fields` (a data item's fields, `DataItem::fields()`), `yaymatik_trigger_provides` (the data items a trigger provides, `Trigger::resolved_provides()`), `yaymatik_trigger_refs` (a trigger's scope refs, `Trigger::resolved_refs()`; a null native ref stays null).
  - Action `yaymatik_cart_abandoned( $cart_id, $kind )`: a cart was abandoned, on every abandon; `$kind` is `cart_abandoned` or `checkout_abandoned`.
- **Frozen by Extension API v1 (1.1), see [extension-api.md](extension-api.md).** Other plugins integrate through `yaymatik_register` and its registrar; the filters and actions above stay internal (Pro uses them) or experimental, as its §11 lists. Classes under `YayMatik\Workflow\` are internal, including the base classes (`Step`, `Module`, `DataItem`, marked `@internal`).
- **Each Yay plugin owns its steps, templates and data types**, registered into Workflow Core. **YayMatik Pro** owns templates that combine several plugins.
- **Plugin logo (L5).** Each module gives one logo, a one-colour SVG: a URL, or for core's modules a `data:` URI of an inline SVG, so it loads when assets come from a CDN. The admin app draws it as a CSS mask in the tile's text colour. It marks who provides a step or template (template cards, library sidebar, picker), never what a step does. One per plugin, not per step.
- **Feature directory:** a static list in core of every known step and template, its plugin, and where to install it. The editor shows "Install X to use this"; the AI uses it to suggest features.

## §9 Health check · P6

Settings → Health check finds problems and proposes a fix the site owner can apply.

| Check | Proposed fix |
|---|---|
| WP-Cron off or running late; Action Scheduler lag or failed jobs | How to set up a server cron; host guides |
| Large tables (runs, Action Scheduler) | Shorter retention; cleanup now |
| Slow query triggers on big stores | Smaller batch size |
| Email delivery not configured | Suggest an SMTP plugin (YaySMTP) |
| `missing_plugin`: active workflows use a step from an inactive plugin. Such a workflow can be opened, edited and saved, but not activated or updated; an active one keeps running and skips that step with a reason ([extension-api.md](extension-api.md) §10) | None: count, workflow links, Plugins page link |
| (a) `runs_queued`: queued over 5 min, or waiting 15 min past `wait_until`, with no pending or in-progress `yaymatik_run` or `yaymatik_recover_run` job (an overdue job still counts: that is (c)) | Queue them again |
| (b) `runs_running`: running with no `yaymatik_run` and no `yaymatik_recover_run` job | Queue recovery; the interrupted step fails, it never runs twice |
| (c) `jobs_late`: pending `yaymatik` jobs over 15 min past due | Info only: server cron guide link |
| (d) `cleanup_job`: `yaymatik_cleanup` not scheduled, or over 1 h past due | Schedule it again |
| (e) `inactive_sweep_job`: `yaymatik_inactive_sweep` not scheduled, or over 1 day past due | Schedule it again |
| Extensions: other plugins' modules and their errors in the last 24 h (`Extension/Diagnostics`, autoloaded option `yaymatik_extension_errors`: last 50, one write per module and callback per 10 minutes, so a count is a lower bound; also logged); a second claim of a taken module, step or data item id is one, a refused module named by its class | None: listed only |

Built: (a)–(e) in `Core/Runtime/HealthCheck.php`; REST `GET /health/checks` and `POST /health/fix { check }` in `Engine/API/HealthRoutes.php` (`manage_options`). Responses carry data only (`id`, `status`, `count`, `runIds` (the 50 oldest), `fix`), plus `extensions` (`HealthCheck::extensions()`: `id`, `label`, `errors`) on the GET; the admin app words them. The run checks read at most the 500 oldest candidate runs; a full read adds `capped: true` and `count` means "at least". A fix runs its check again on the server and fixes what it finds then (at most 50 runs per call), never ids from the client. Panel (M7.7, `routes/settings/HealthPanel.tsx`): the last card of Settings; it words the checks and offers one click per fixable check (at most 50 runs each).

## §10 Deferred decisions

| ID | Topic | Decision |
|---|---|---|
| P3 | Waiting runs on Update | Always cancel in v1.0. Revisit after v1.0 |
| P4 | Public extension API | 1.1, plan 034: [extension-api.md](extension-api.md). The registry and step contract stay internal behind it |
| P5 | AI provider | Not decided. v1.0 only keeps a provider interface open |

## §11 Decision log

All confirmed 2026-10-07, except L5 (2026-10-08), M7 (2026-10-27), M8, M3–M5, M6, CT and EX (2026-11-13). Reasons, rejected options and costs: [workflow-core-decisions.md](workflow-core-decisions.md).

| ID | Decision | Section |
|---|---|---|
| C1 | Workflow Core moves to `includes/Workflow/`; `Engine/` keeps WP/WC glue only | §1 |
| C2 | Blocks → Steps, PHP and TS | §1 |
| C3 | Stored runs: `runs` + `run_steps` | §2 |
| C4 | Action Scheduler stays as clock and worker; `runs` is the source of truth | §3 |
| C5 | Runs execute in slices with a row lock | §3 |
| C6 | Scope stores refs, loaded lazily, plus trigger-time snapshots | §4 |
| C7 | Step outputs, scopes, typed data with relations | §4 |
| C8 | Runs pin a version; a version's logic never changes (its layout may) | §2 |
| C9 | Stored causation replaces the in-memory loop guard | §3 |
| C10 | Custom tables replace the CPT before v1.0; dev data reset, no migration | §2 |
| C11 | Scheduled / query triggers fan out per subject with `trigger_claims` | §3 |
| L2 | Step settings defined in PHP; editor checks built from them; small generic form; core imports custom forms; plugin steps with custom forms wait for the public API, P4 | §5 |
| L3 | Own template module, tiers 1 → 2 → escape valve; no Liquid | §6 |
| L4 | Jack builds everything: F0 rename, then F1 foundation before M1; F2 workflows table after M2 | [ROADMAP-dev.md](ROADMAP-dev.md) |
| L5 | Step icons: a lucide name from core's list; plugin logos: one SVG file per plugin | §5, §8 |
| P1 | Runtime free; Free vs Pro by folder | §8 |
| P2 | Yay plugins own their steps; Pro owns multi-plugin templates; feature directory | §8 |
| P3 | Cancel waiting runs on Update in v1.0 | §10 |
| P4 | Public extension API in 1.1 (plan 034) | §10 |
| P5 | AI provider deferred | §10 |
| P6 | Settings → Health check | §9 |
| P7 | Tree + container steps; no merge | §7 |
| M7 | Carts in their own table; no own tracking cookie; capture any email, same response; restore GET writes nothing; erase anonymizes runs | §2 |
| M8 | Abandon sweep, a cart's cycle is its claim | §3 |
| M3–M5 | High-value on a status change; reviews read when the step runs; account trigger refuses saved `WC_Customer`s; billing fill in memory | §4, §6 |
| M6 | Win-back on a person's last real order, in a 7-day window, once per workflow and order through `trigger_claims` | §2, §3 |
| CT | Custom workflow catalog: customer stats, Order paid, milestones, Review posted, guarded role change, condition groups | §3, §4, §5 |
| EX | Extension API v1: declarative registrar, inactive plugin's steps kept but block Activate | §5, §8, §9 |
