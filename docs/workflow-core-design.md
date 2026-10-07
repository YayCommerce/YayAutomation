# Workflow Core — design and decisions

**Status:** Planned, not yet built · **Decisions confirmed:** 2026-10-07 · **Roadmap:** [ROADMAP-dev.md](ROADMAP-dev.md) · **Product scope:** [ROADMAP-v1.0.md](ROADMAP-v1.0.md)

"Workflow Core" is the code that stores, checks and runs workflows. It is not called "engine": in this repo `includes/Engine/` means WP/WC glue. This doc holds the decisions and the interfaces; the reasons behind them are in [workflow-core-decisions.md](workflow-core-decisions.md). The roadmap refers to sections here as `design §N`.

## §0 Current state (v0.1)

| Area | Today | File |
|---|---|---|
| Storage | CPT `yay_automation_wf` + post meta (status, trigger id, live graph JSON, draft graph JSON). Versions in table `yay_automation_versions` | `includes/Engine/Workflows/WorkflowRepository.php`, `VersionStore.php` |
| Graph | React Flow shape (nodes with positions, edges). Tree: one parent per step, one child per handle. Kinds: trigger, condition (`then` / `otherwise`), action | `includes/Engine/Workflows/WorkflowValidator.php` |
| Trigger → queue | Triggers hook WP actions → `collect()` while the request runs → `flush()` at shutdown → `meta_query` for active workflows → `claim()` → `matches()` → one Action Scheduler async job per workflow | `includes/Engine/Workflows/Runner.php` |
| Execution | One job walks the whole graph at once. It reads the live graph at run time, not the version live when the event happened | `Runner::run()` |
| Run state | None. Errors go to the WooCommerce log. No runs, step results or outputs are stored | `includes/Utils/Logger.php` |
| Context | Item id => live PHP object (`WC_Order`), rebuilt from event args. Can't be saved mid-run | `Blocks/Triggers/OrderTrigger.php` |
| Data | Fields typed number / string / enum / date. 3 items: order, customer, site. Merge tags have 2 parts (`{{ order.total }}`). No booleans, lists, relations or step outputs | `includes/Engine/Data/` |
| Actions | `run()` returns `true` or `WP_Error`, no output | `Blocks/Core/Action.php` |
| Loop guard | In-memory chain of workflow ids per subject, passed in job args | `Runner::collect()` |
| Extending | Hard-coded lists in `BlockRegistry`, `DataRegistry`, `Plugins`. No hooks | `Blocks/Core/BlockRegistry.php` |
| Validation | Written twice: PHP `validate()` must match each block's TS `schema.ts` by hand | `Blocks/Core/Block.php` |

**Keep:** batching at shutdown, `claim()` for once-per-subject triggers, the sanitize / validate split, versions, `uses()` / `provides()` checks, "never throw into the host request", Action Scheduler.

**Blocks the roadmap:**
1. No stored run: no Wait, run history, retry, cancel or AI debugging.
2. One job runs the whole graph: it can't pause, resume or process a loop in parts.
3. The context can't be saved mid-run.
4. Steps have no outputs: Generate coupon → coupon code in an email is impossible.
5. No lists, relations or booleans: For each, `order.items` and yes/no conditions don't fit.
6. Event triggers only: no schedule, interval, query, manual or call-by-workflow.
7. Closed registries: Yay plugins can't add steps.
8. Two validators kept in sync by hand: doesn't scale to plugin steps, and the AI can't read them.

## §1 Naming and layout · C1, C2

- `includes/Engine/` keeps only WP/WC glue: `BEPages/`, `API/`, `Register/`. REST controllers call Workflow Core and hold no workflow logic.
- Workflow Core moves to `includes/Workflow/`, namespace `YayAutomation\Workflow\`.
- "Blocks" becomes **"Steps"**, in PHP and TS (`apps/admin-page/src/routes/workflows/blocks/` → `steps/`). This avoids a clash with Gutenberg blocks and matches the UI wording.

| Folder | Holds |
|---|---|
| `Storage/` | Tables, upgrades, `WorkflowStore`, `VersionStore`, `RunStore`, `ClaimStore` |
| `Definition/` | Graph schema, `Validator`, `Catalog` (steps, fields, outputs, data types → admin app and AI) |
| `Data/` | Types, entity types, `RefResolver`, `Scope`, `Template` |
| `Runtime/` | `Dispatcher`, `Scheduler`, `Executor`, `Worker`, `Cancellation`, `Retention` |
| `Steps/` | `Triggers/`, `Actions/`, `Conditions/`, `Control/` (Wait, For each, Switch, Call workflow, Set variable, Stop) |
| `Extend/` | Registry, modules, connectors |
| `AI/` | Provider interface and AI features (v2.0) |

## §2 Storage · C3, C8, C10

Custom tables replace the CPT and post meta. Upgrades use `dbDelta` + a DB version option, like `VersionStore::maybe_install()` today.

| Table | Columns (main) |
|---|---|
| `workflows` | id, name, status, trigger, live_version_id, draft_version_id, activated_at, created_at, updated_at · index (status, trigger) |
| `workflow_versions` | id, workflow_id, action, graph, user_id, created_at. A saved version never changes; only the draft row is edited in place |
| `runs` | id, workflow_id, version_id, trigger, subject, status, cursor, scope, wait_until, error, parent_run_id, root_run_id, depth, started_at, finished_at |
| `run_steps` | id, run_id, step_id, iteration, status, input, output, error, attempt, started_at, finished_at |
| `trigger_claims` | workflow_id, subject, claimed_at · unique (workflow_id, subject) |

- A run pins the version it started on.
- Why tables, not CPT: an indexed lookup on every event instead of a `meta_query`, no JSON slashing, atomic writes, simple cleanup.
- The CPT migration must ship before v1.0. After release it would touch merchants' data.

## §3 Runtime · C4, C5, C9, C11, P3

**Run states:** queued → running → waiting → completed / failed / cancelled / skipped.

- **Dispatcher.** Keeps today's flow: collect events during the request, match at shutdown. It now creates a `runs` row per matching workflow and queues it.
- **Worker.** Action Scheduler is the clock and the worker; the `runs` table is the source of truth. A job takes a row lock (`UPDATE … WHERE status IN (…)`) so two workers never run the same run.
- **Slices.** A job keeps running steps until the run finishes, hits a Wait, uses ~20 s or its memory budget, or ends a loop batch. It then saves the cursor and queues the next slice.
- **Errors.** Each step has a policy: stop the run (default) or continue. Retries come in v1.1.
- **Loop safety.** `parent_run_id`, `root_run_id`, `depth` replace the in-memory chain. Max depth, a per-workflow rate limit that pauses a runaway workflow, and a max loop size.
- **Scheduled and query triggers.** A schedule fans out to one run per matching subject, in batches. `trigger_claims` stops duplicates ("once per customer per 90 days"). This generalises the `_yay_automation_order_created` order meta in `OrderCreated::claim()`.
- **Cancel.** Turn off and Apply changes cancel waiting runs (v1.0 rule). The API returns the count for the UI.

## §4 Data layer · C6, C7

- **Types:** string, number, boolean, date, datetime, money, enum, entity\<T\>, list\<T\>, object.
- **Entity types** with relations: order (`customer`, `items`), order_item (`product`), customer, product, coupon, cart, site.
- **Scope** paths: `trigger.*` (with `order` / `customer` shortcuts), `steps.<id>.*`, `loop.item` / `loop.index`, `vars.*`, `workflow.*`, `run.*`, `site.*`.
- **Refs, loaded lazily.** Entities are stored as `{type: 'order', id: 12}` and loaded on demand, cached per slice. Facts only true at trigger time (status from / to, cart contents, event payload) are snapshotted. Data is fresh after a long Wait, and rows stay small.
- **Gone entity.** If an order or customer was deleted, the step is skipped and run history says why.
- **Available variables per step.** Each step declares outputs. The Validator walks the steps above each step to list what it can use. "Add a variable" and the template checks use the same list.

## §5 Step contract · L2

**PHP is the one source of truth for a step's settings.** The editor builds its checks from it, so PHP and TS can't disagree.

```
Step:   id() label() plugin() module()
        fields(): Field[]        settings, rules, widgets
        validate( config )       optional extra checks the field rules can't express
        outputs(): Field[]       what later steps can use
        summary(): string        card text, e.g. "Send email to %recipients%"
        run( Scope, config ): StepResult   ok( outputs ) | skipped( reason ) | failed( error )
```

- **Field vocabulary** (kept small): text, textarea, emails, select, multiselect, toggle, number, number_unit, notice, rules (core only). Modifiers: `required`, `variables`, `placeholder`, `help`, `show_if`. A form that needs more gets a custom panel, not a new word.
- **Catalog.** Steps, fields, outputs and data types go to the admin app through `yayAutomationMeta` (and to the AI in v2.0).
- **Editor checks.** Automation builds valibot rules from the PHP fields. Validation always runs in Automation, never in a panel. Rules only in PHP `validate()` show up as the step's issue after a save, and still block Turn on.
- **Forms.**
  - No custom form → the generic `StepForm` draws one from the fields. A simple step from a Yay plugin needs PHP only.
  - Core step with a complex form (Send email, Condition rules) → `defineStep( { id, Form } )`, imported directly. No global call, fully typed.
  - Plugin step with a custom form → `window.yayAutomation.registerStepPanel( id, Panel )`. Internal until §10.
  - Both put the form in the same registry. Props: `{ config, onChange, errors }`.
- The `rules` field type is ours: its checks are written in PHP (`Criteria`) and TS. Plugins never write validation in JS.

## §6 Templates · L3

Our own small template module. **No Liquid or other template library, now or later.**

- **Tier 1 (v1.0):** `{{ path | filter: arg }}`, e.g. `{{ order.customer.first_name | default: "there" }}`.
  - Filter registry: `default`, `date`, `money`, `upper`, `lower`, `join`. Each filter declares its input type. Yay plugins may add filters later.
  - The editor checks paths and filters against the step's available variables (§4).
  - A missing value prints empty; the step never fails for it.
  - Output is escaped in HTML. No `raw` filter for users.
  - **One renderer, in PHP.** TS only parses, for checks and autocomplete. Preview and Send test call the server.
  - Today's `{{ item.field }}` tags keep working.
- **Tier 2 (on demand):** lists come from server-rendered email blocks (product grid, cart items, coupon box). Conditional text comes from a "Show if" setting on a block, using the Condition rules builder.
- **Escape valve (only if users ask):** `{% if path %}…{% else %}…{% endif %}`, truthy check only.

## §7 Graph · P7

- One parent per step. Condition branches `then` / `otherwise`; Switch has N handles; For each has `body` and `done`.
- **Branches never merge.** Shared steps go in a separate workflow started by Call workflow.
- Stored JSON gets `schemaVersion`, with logic kept apart from layout (positions).

## §8 Modules, tiers and the Yay ecosystem · P1, P2

- The runtime is **100% free**: executor, Wait, loops, Call workflow, scheduled triggers, run history.
- Steps, templates, conditions and features (run logs, connectors, AI, each integration) belong to **modules**. A manifest entry gives each module its tier (`lite` / `pro`) and an on/off switch.
  - Moving something between Lite and Pro = change its manifest entry.
  - Turning off a module keeps unfinished work out of a release.
- **Each Yay plugin owns its steps, templates and data types**, registered into Workflow Core. **YayAutomation Pro** owns templates that combine several plugins.
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

## §10 Deferred decisions

| ID | Topic | Decision |
|---|---|---|
| P3 | Waiting runs on Apply changes | Always cancel in v1.0. Revisit after v1.0 |
| P4 | Public extension API | Last: after Pro connectors and Yay integrations are stable. Until then the registry, step contract and `registerStepPanel` are internal |
| P5 | AI provider | Not decided. v1.0 only keeps a provider interface open |

## §11 Decision log

All confirmed 2026-10-07. Reasons, rejected options and costs: [workflow-core-decisions.md](workflow-core-decisions.md).

| ID | Decision | Section |
|---|---|---|
| C1 | Workflow Core moves to `includes/Workflow/`; `Engine/` keeps WP/WC glue only | §1 |
| C2 | Blocks → Steps, PHP and TS | §1 |
| C3 | Stored runs: `runs` + `run_steps` | §2 |
| C4 | Action Scheduler stays as clock and worker; `runs` is the source of truth | §3 |
| C5 | Runs execute in slices with a row lock | §3 |
| C6 | Scope stores refs, loaded lazily, plus trigger-time snapshots | §4 |
| C7 | Step outputs, scopes, typed data with relations | §4 |
| C8 | Runs pin a version; saved versions never change | §2 |
| C9 | Stored causation replaces the in-memory loop guard | §3 |
| C10 | Custom tables replace the CPT, migrated before v1.0 | §2 |
| C11 | Scheduled / query triggers fan out per subject with `trigger_claims` | §3 |
| L2 | Step settings defined in PHP; editor checks built from them; small generic form; core imports custom forms; plugins use `registerStepPanel` | §5 |
| L3 | Own template module, tiers 1 → 2 → escape valve; no Liquid | §6 |
| L4 | Jack builds everything: F0 rename, then F1 foundation before M1; F2 workflows table after M2 | [ROADMAP-dev.md](ROADMAP-dev.md) |
| P1 | Runtime free; modules with tier + switch | §8 |
| P2 | Yay plugins own their steps; Pro owns multi-plugin templates; feature directory | §8 |
| P3 | Cancel waiting runs on Apply in v1.0 | §10 |
| P4 | Public extension API last | §10 |
| P5 | AI provider deferred | §10 |
| P6 | Settings → Health check | §9 |
| P7 | Tree + container steps; no merge | §7 |
