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

**Decision.** A run keeps the version it started with. Saved versions never change.

**Why.** Today a queued job reads whatever graph is live when it runs. With Waits, a 3-email series could start on version 1 and finish on version 3 with different steps. Pinning makes every run follow one known graph, and run detail can show it.

**Cost.** Versions are only ever added, never edited. Only the draft row is edited in place.

**For devs.** Never load "the live graph" inside the executor. Load `runs.version_id`.

## C9. Stored causation for loop safety

**Decision.** Each run stores `parent_run_id`, `root_run_id`, `depth`. Max depth, plus a per-workflow rate limit that pauses a runaway workflow.

**Why.** Today's guard is an in-memory list passed in job args. It works for one request, but not across Waits, Call workflow or scheduled triggers, which can loop over hours.

**For devs.** Anything that starts a run from inside a run (Call workflow, an action that fires a trigger) passes the parent run.

## C10. Custom tables replace the CPT

**Decision.** `workflows` and `workflow_versions` tables replace the `yaymatik_wf` post type and its meta.

**Why.**
- Every store event runs a `meta_query` to find active workflows. That's slow, and it runs on checkout.
- Graph JSON in post meta needs slashing tricks (see `WorkflowRepository::write_graph()`).
- Several meta writes per save can half-fail.

**Rejected.** Keep the CPT and cache the trigger lookup: fixes speed, keeps the rest.

**Cost.** One migration (F2). It **must ship before v1.0**: after release it would touch merchants' data. We lose WP's export tool → JSON import/export later.

**For devs.** Workflows are read through `WorkflowStore`, never `get_posts()`.

## C11. Scheduled and query triggers with claims

**Decision.** A schedule fans out to one run per matching subject, in batches. Table `trigger_claims` records "this workflow already ran for this subject".

**Why.**
- Inactive customer (M6) and abandoned cart (M8) aren't events. Something has to look for them on a schedule.
- Without claims, the daily check would email the same customer every day.
- It generalises today's trick in `OrderCreated::claim()` (an order meta flag).

**For devs.** A query trigger defines "find subjects" and "subject key". Batching and dedupe come from the Scheduler.

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
