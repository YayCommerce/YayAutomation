# Workflow Core — design and decisions

**Status:** F1 backend built (storage §2, runtime §3, data layer §4, step contract §5, templates §6, modules §8); the rest is planned · **Decisions confirmed:** 2026-10-07 · **Roadmap:** [ROADMAP-dev.md](ROADMAP-dev.md) · **Product scope:** [ROADMAP-v1.0.md](ROADMAP-v1.0.md)

"Workflow Core" is the code that stores, checks and runs workflows. It is not called "engine": in this repo `includes/Engine/` means WP/WC glue. This doc holds the decisions and the interfaces; the reasons behind them are in [workflow-core-decisions.md](workflow-core-decisions.md). The roadmap refers to sections here as `design §N`.

## §1 Naming and layout · C1, C2

- `includes/Engine/` keeps only WP/WC glue: `BEPages/`, `API/`, `Register/`. REST controllers call Workflow Core and hold no workflow logic.
- Workflow Core moves to `includes/Workflow/`, namespace `YayMatik\Workflow\`. The top level holds what modules register; `Core/` holds the shared code every module uses. `includes/Engine/` = WordPress glue (REST, admin pages, scripts). A folder is named for the plural of the base class it holds; namespaces follow folders.

| Folder | Holds |
|---|---|
| `ModuleRegistry.php`, `Modules/` | The modules and what they register (§8); planned: connectors |
| `Triggers/`, `Conditions/`, `Actions/` | One class per step; Wait (an action, YayMatik module); planned: control steps (For each, Switch, Call workflow, Set variable, Stop) |
| `DataItems/` | Data items (order, customer, site, order_item) |
| `Core/Steps/` | The step contract (`Step`, `Trigger`, `Condition`, `Action`, `Field`, `StepResult`, `StepIcons`, `StepRegistry`) |
| `Core/Data/` | `DataItem`, `DataRegistry`, `Type`, `Operators`, `Ref`, `RefResolver`, `Scope`, `Template`, `Filters` |
| `Core/Definition/` | Graph schema, `Validator`, `Catalog` (steps, fields, outputs, data types → admin app and AI) |
| `Core/Runtime/` | `Dispatcher`, `Executor`, `Worker`, `Runtime`, `Cancellation`; planned: `Scheduler`, `Retention` |
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
| `runs` | id, workflow_id, version_id, trigger_id, subject, status, position, scope, wait_until, error, parent_run_id, root_run_id, depth, started_at, finished_at |
| `run_steps` | id, run_id, step_id, iteration, status, input, output, error, attempt, started_at, finished_at |
| `carts` | id, session_key (WooCommerce session: user id or guest `t_…`; unique), user_id, email, first_name, items (JSON lines), total, currency, status (active, ordered), checkout_at, order_id, restored_at, last_activity_at, created_at, updated_at |

- A run pins the version it started on.
- Deleting a workflow deletes its versions; its runs stay until retention cleanup (M7.4).
- Why tables, not CPT: an indexed lookup on every event instead of a `meta_query`, no JSON slashing, atomic writes, simple cleanup.
- Tables replace the CPT before v1.0, while no merchant has data: dev data is reset (`./run.sh reset-data`), no migration.

## §3 Runtime · C4, C5, C9, C11, P3

**Run states:** queued → running → waiting → completed / failed / cancelled / skipped.

- **Dispatcher.** Keeps today's flow: collect events during the request, match at shutdown. It now creates a `runs` row per matching workflow and queues it.
- **Worker.** Action Scheduler is the clock and the worker; the `runs` table is the source of truth. A job takes a row lock (`UPDATE … WHERE status IN (…)`) so two workers never run the same run.
- **Slices.** A job keeps running steps until the run finishes, hits a Wait, uses ~20 s or its memory budget, or ends a loop batch. It then saves its position (the `position` column) and queues the next slice.
- **Errors.** Each step has a policy: stop the run (default) or continue. Retries come in v1.1.
- **Loop safety.** `parent_run_id`, `root_run_id`, `depth` replace the in-memory chain. A workflow never starts again inside its own chain; max depth 5; a max loop size. Rate limits per workflow, per 60 s, pause (turn off) a runaway workflow: 100 runs started by other runs (a loop always has a parent), or 1000 runs in all. Runs from plain events only count toward the 1000, so a bulk edit of hundreds of orders never pauses a workflow.
- **Scheduled and query triggers.** A schedule fans out to one run per matching subject, in batches. `trigger_claims` stops duplicates ("once per customer per 90 days"). This generalises the `_yaymatik_order_created` order meta in `OrderCreated::claim()`.
- **Cancel.** Deactivate, Update (Apply) and the rate-limit pause cancel waiting runs (v1.0 rule) in one place, `Cancellation::waiting()`, under the workflow lock (`WorkflowStore::locked()`, shared by REST and the rate-limit pause; a pause that can't take it is logged, and the workflow stays paused for the rest of the request): it selects the waiting runs, cancels those still waiting in one UPDATE, then marks their waiting step rows `cancelled`. Deactivate and Apply return the count (`cancelledRuns`). The runs' Action Scheduler jobs stay; when one fires it can't take the run and does nothing. Apply leaves queued and running runs on their pinned version.
- **Stop right away.** Before each step, and after saving a wait, the Executor reads the workflow's status fresh (not from the object cache); once it is off, the run ends `cancelled` ("The workflow was deactivated."). A run that ends this way, or before its first step, closes its open step rows (`running`, `waiting`).
- **Cleanup.** A daily Action Scheduler job (`yaymatik_cleanup`, group `yaymatik`, `Runtime/Cleanup`) deletes finished runs with their steps (deleted workflows' runs included) that finished more than `run_retention_days` ago, then carts last active more than `cart_retention_days` ago. Batches of 500 within ~20 s; what's left goes the next day. Queued, running and waiting runs are never deleted, nor any run of a chain (`root_run_id`) that still has one: the loop guard walks `parent_run_id`. `Cleanup::ensure()` on `action_scheduler_init` schedules the job when it's missing, checked at most hourly through the autoloaded option `yaymatik_cleanup_checked_at` (Unix time).
- **Known limit.** Action Scheduler fails a job still running after its timeout (~5 min). If a step is still running then, a recovery job can take the run while the old worker is alive. Slices stay far below that (~20 s), so only a single very slow step can hit it.

## §4 Data layer · C6, C7

- **Types:** string, number, boolean, date, money, enum, entity\<T\>, list\<T\>.
- **Entity types** with relations: order (`customer`, `items`), order_item (`product`), customer, product, coupon, cart, site.
- **Scope** paths: `trigger.*` (with `order` / `customer` shortcuts), `steps.<id>.*`, `loop.item` / `loop.index`, `run.*`, `site.*`.
- **Refs, loaded lazily.** Entities are stored as `{type: 'order', id: 12}` and loaded on demand, cached per slice. Facts only true at trigger time (status from / to, cart contents, event payload) are snapshotted. Data is fresh after a long Wait, and rows stay small.
- **Gone entity.** If an order or customer was deleted, the step is skipped and run history says why.
- **Available variables per step.** Each step declares outputs. The Validator walks the steps above each step to list what it can use. "Insert variable" and the template checks use the same list.

## §5 Step contract · L2

**PHP is the one source of truth for a step's settings.** The editor builds its checks from it, so PHP and TS can't disagree.

```
Step:   id() label() plugin() module()
        icon(): string           a name from core's step icon list, e.g. "mail" (L5)
        fields(): Field[]        settings, rules, widgets
        validate( config )       optional extra checks the field rules can't express
        outputs(): array         what later steps can use: key => [ label, type, options?, display?, block? ]
        sample_outputs( config ) made-up outputs for previews of later steps (Generate coupon → YMSAMPLE)
        summary(): string        card text, one short line, e.g. "Send to %recipients%"
        run( Scope, config ): StepResult   ok( outputs ) | skipped( reason ) | failed( error )
```

- **Field vocabulary** (kept small): text, textarea, html (rich text, cleaned with `wp_kses_post`; drawn as WordPress's classic editor, Visual / Code tabs, stored as WordPress saves it: paragraphs as blank lines or `<p>`, `wpautop` puts them back), emails, select, multiselect, toggle, number, number_unit, notice, rules (core only). Modifiers: `required`, `variables`, `placeholder`, `help`, `show_if`. A form that needs more gets a custom panel, not a new word.
- **Catalog.** Steps, fields, outputs and data types go to the admin app through `yayMatikMeta` (and to the AI in v2.0).
- **Editor checks.** Automation builds valibot rules from the PHP fields. Validation always runs in Automation, never in a panel. Rules only in PHP `validate()` show up as the step's issue after a save (under its field when the form draws it, else at the top of the panel; "Needs setup" on the card), and still block Activate.
- **Forms.**
  - No custom form → the generic `StepForm` draws one from the fields. A simple step from a Yay plugin needs PHP only.
  - Core step with a complex form (Condition rules) → `defineStep( { id, Form } )`, imported directly. No global call, fully typed.
  - Plugin step with a custom form → planned, through the public extension API (§10 P4).
  - A custom `Form` gets `{ config, onChange }` (`StepFormProps`).
  - Beside any form, `defineStep` takes `Footer` (under the form, with its latest config, e.g. Send email's unsubscribe note) and `Actions` (panel header, e.g. Send email's Preview / Send test). Send email and Generate coupon use the generic form plus these.
- **Icons (L5).** A step's `icon()` is a name from a fixed list in core (lucide icons, about 40), never an SVG. The editor draws it in the step's tile, tinted by kind (trigger, condition, action), on the canvas, in the picker, the panel header, template cards and the preview. A missing icon is added to core's list, not shipped by the plugin.
- The `rules` field type is ours: its checks are written in PHP (`Criteria`) and TS. Plugins never write validation in JS.
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
- **Tier 2 (on demand):** lists come from server-rendered email blocks (product grid, cart items, coupon box; the coupon box and product grid exist). A product grid is a data field marked `block` (`order.cross_sell_products`, `order.upsell_products`, `Email\ProductGrid`): up to 4 published, visible, in-stock suggestions not already in the order, 2 per row (an odd last one centered), each image scaled into a square of up to 160px (narrower with the column on phones), names cut at 60 characters, as wide and spaced as the coupon box; none → empty, so the block is left out. With sample data it shows the store's 4 newest products. "Insert variable" offers it under Blocks, in a section named after its data item, in rich text fields only (never in a subject, heading, To or condition). Conditional text comes from a "Show if" setting on a block, using the Condition rules builder.
- **Escape valve (only if users ask):** `{% if path %}…{% else %}…{% endif %}`, truthy check only.

**Customer emails** (Send email):
- WooCommerce sender and email template, styles inlined by WooCommerce's mailer. One To address; the final address (after variables) decides.
- Every customer email has an "Unsubscribe" link under the message and `List-Unsubscribe` / `List-Unsubscribe-Post` (one-click) headers. The link carries base64url(email) and an HMAC of it (own secret, no expiry). Unsubscribes are per address, for all YayMatik customer emails; the step is skipped ("The customer unsubscribed from these emails." in run history) and the run goes on. Staff email ignores them.
- **Preview / Send test** render only, from the editor's unsaved step and graph: the latest paid order (its number shown), or made-up data (`DataItem::sample()`, flagged for the "Sample data" badge); steps above give sample outputs. The unsubscribe link goes nowhere (`#`) and there are no List-Unsubscribe headers. A test goes to the logged-in admin, subject prefixed `[Test]`. A step with issues is refused (400 with `issues`), listed in the panel.

## §7 Graph · P7

- One parent per step. Condition branches `then` / `otherwise`; Switch has N handles; For each has `body` and `done`.
- **Branches never merge.** Shared steps go in a separate workflow started by Call workflow.
- A version's `graph` holds the logic (`schemaVersion`, steps without positions, links); its `layout` holds step positions by step id. REST and the editor get one graph with `position` on each step. Logic alone decides drafts and versions: moving a card rewrites only the layout of the row being edited (`WorkflowStore::save_layout()`), and Activate / Deactivate copy the layout to the new version.

## §8 Modules and the Yay ecosystem · P1, P2

- The runtime is **100% free**: executor, Wait, loops, Call workflow, scheduled triggers, run history.
- Steps and data items belong to **modules**, one per plugin (`ModuleRegistry`, `Modules/`). A module lists its triggers, conditions, actions and data items by class name; the catalog sends its id as each step's `plugin`.
  - **Free vs Pro is the folder.** `includes/Pro/` holds Pro modules that extend the free ones; the free zip leaves it out, and the registry loads the Pro modules only when their classes exist.
  - An unfinished step is not registered, so it stays out of a release. No tier checks inside steps.
  - Another plugin adds its module class on the filter `yaymatik_modules`, before `init` (the registry is first read on `init`). A taken id (module, step, data item) keeps its first owner.
  - Filters for extending core's steps and data: `yaymatik_data_item_{id}_fields` (a data item's fields, `DataItem::fields()`), `yaymatik_trigger_provides` (the data items a trigger provides, `Trigger::resolved_provides()`), `yaymatik_trigger_refs` (a trigger's scope refs, `Trigger::resolved_refs()`; a null native ref stays null).
- **Each Yay plugin owns its steps, templates and data types**, registered into Workflow Core. **YayMatik Pro** owns templates that combine several plugins.
- **Plugin logo (L5).** Each module gives one logo: the URL of a one-colour SVG file its plugin ships (core's in `assets/logos/`). The admin app draws it as a CSS mask in the tile's text colour. It marks who provides a step or template (template cards, library sidebar, picker), never what a step does. One per plugin, not per step.
- **Feature directory:** a static list in core of every known step and template, its plugin, and where to install it. The editor shows "Install X to use this"; the AI uses it to suggest features.

## §9 Health check · P6

Settings → Health check finds problems and proposes a fix the site owner can apply.

| Check | Proposed fix |
|---|---|
| WP-Cron off or running late; Action Scheduler lag or failed jobs | How to set up a server cron; host guides |
| Large tables (runs, Action Scheduler) | Shorter retention; cleanup now |
| Slow query triggers on big stores | Smaller batch size |
| Email delivery not configured | Suggest an SMTP plugin (YaySMTP) |
| Active workflow uses a step from a missing plugin | Install link from the feature directory |
| (a) `runs_queued`: queued over 5 min, or waiting 15 min past `wait_until`, with no pending or in-progress `yaymatik_run` or `yaymatik_recover_run` job (an overdue job still counts: that is (c)) | Queue them again |
| (b) `runs_running`: running with no `yaymatik_run` and no `yaymatik_recover_run` job | Queue recovery; the interrupted step fails, it never runs twice |
| (c) `jobs_late`: pending `yaymatik` jobs over 15 min past due | Info only: server cron guide link |
| (d) `cleanup_job`: `yaymatik_cleanup` not scheduled, or over 1 h past due | Schedule it again |

Built: (a)–(d) in `Core/Runtime/HealthCheck.php`; REST `GET /health/checks` and `POST /health/fix { check }` in `Engine/API/HealthRoutes.php` (`manage_options`). Responses carry data only (`id`, `status`, `count`, `runIds` (the 50 oldest), `fix`); the admin app words them. The run checks read at most the 500 oldest candidate runs; a full read adds `capped: true` and `count` means "at least". A fix runs its check again on the server and fixes what it finds then (at most 50 runs per call), never ids from the client.

## §10 Deferred decisions

| ID | Topic | Decision |
|---|---|---|
| P3 | Waiting runs on Update | Always cancel in v1.0. Revisit after v1.0 |
| P4 | Public extension API | Last: after Pro connectors and Yay integrations are stable. Until then the registry and step contract are internal, and plugin steps have no custom forms |
| P5 | AI provider | Not decided. v1.0 only keeps a provider interface open |

## §11 Decision log

All confirmed 2026-10-07, except L5 (2026-10-08) and M7 (2026-10-27). Reasons, rejected options and costs: [workflow-core-decisions.md](workflow-core-decisions.md).

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
| P4 | Public extension API last | §10 |
| P5 | AI provider deferred | §10 |
| P6 | Settings → Health check | §9 |
| P7 | Tree + container steps; no merge | §7 |
| M7 | Carts in their own table; no own tracking cookie; capture any email, same response; restore GET writes nothing; erase anonymizes runs | §2 |
