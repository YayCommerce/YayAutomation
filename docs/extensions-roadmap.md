# Extensions roadmap

Where the Extension API goes after v1 ([extension-api.md](extension-api.md)). **Planned, not built.** Decisions are numbered as discussed; open ones carry a recommendation.

**Words.** An *extension* is a plugin's integration through the Extension API. A *connector* is an outside service only (Slack, Sheets, webhooks: V12.4–12.5 in [ROADMAP-dev.md](ROADMAP-dev.md)).

## Scope

YayMatik solves and proves that Yay plugins work together safely in one workflow, one group at a time:

1. **Email stack:** YayMail, YayMagic, YaySMTP.
2. **Customer value stack:** YayPricing, YayWholesale, YayLoyalty.

The other Yay plugins follow once both groups pass (D15).

**Safely** means, for every mix of these plugins active or missing:

- One email step sends one email, wrapped once, logged once.
- A grant (role, points, discount) happens once per run, even when a step retries.
- A missing or broken plugin gives a Skipped step and a diagnostic, never a fatal or a cancelled run.
- The shopper's request does no workflow work beyond recording the event.
- Every customer email passes the same consent check.

**Proved** means YayMatik CI runs each extension from its own repo against YayMatik `develop` (D9), and a scenario test per row of the group's matrix below passes.

**Who builds what.** Lane A (Ecosystem Foundation) builds core's side and the proof: the core changes each group needs, fixture extensions in YayMatik's tests that act like each plugin, the scenario tests and the CI job. Each plugin's real extension is its owner's task, from a brief the lane hands over; the CI job then runs it in place of the fixture.

## Approach

YayMatik runs inside WordPress and WooCommerce; it does not own the runtime. It owns its state (runs, claims, cursors) and borrows the clock (WP-Cron / Action Scheduler), the database, `wp_mail` and WooCommerce hooks. So:

- **Batch everything.** Work goes to background jobs in chunks, never one job per customer up front.
- **Never slow the shopper.** A hook on the shopper's request records the event and returns.
- **Light up by capability.** A feature shows only when the plugin it needs is active and new enough.
- **State timing honestly.** The UI says "within about N minutes", not "now", when the clock is WP-Cron.

Within that, core owns the run and the contract; plugins add domain logic, data and UI as declarations:

1. **Core owns the run.** Queue, waits, retries, claims, versions, history, privacy. Plugins give step bodies (`run` / `build`) and events (`yaymatik_fire`).
2. **Declared, not coded.** Every trigger, action, field and template is an array with labels and types: core validates it, draws its form, generates docs from it, and AI reads it (v2.0).
3. **Additive.** New keys, field types and `supports()` features in MINOR releases; breaking changes only behind `yaymatik_register_v2`.
4. **Degrade softly.** A broken or missing plugin is a diagnostic or a Skipped step, never a fatal or a cancelled run.
5. **Privacy by construction.** People are reached through ids; plugins declare their own subjects.

## Group 1: email stack

| Plugin | Role in a workflow | Extension adds | Core adds |
|---|---|---|---|
| YayMail | Design for order emails | Template kind not bound to a WC email, embedded editor, render by template id + order, skip wrapping a marked email | Mark on every email it sends (D17) |
| YayMagic | Design for any email, with or without an order | Embedded editor, render by template id from `$run`, no globals in shortcodes, skip wrapping a marked email | Mark on every email it sends (D17) |
| YaySMTP | Delivery and its log | Reports delivery result (and opens / clicks, if it tracks them **[Guess]**) for a message YayMatik sent | Delivery status in run detail by the mark, when YaySMTP is active |

Matrix to prove: each design source (core, YayMail, YayMagic) × YaySMTP on / off × the other designer on / off × designer plugin removed after the workflow was saved. Each cell checks: one email, one wrapper, unsubscribe link present, run detail correct.

## Group 2: customer value stack

| Plugin | Role in a workflow | Extension adds | Core adds |
|---|---|---|---|
| YayPricing | Applied rules and discounts as order data | Order and order-item fields (`yaypricing_rules`, `yaypricing_discount_total`) | — |
| YayWholesale | Wholesale role and approval | Trigger "customer approved as wholesaler" (`yaymatik_fire` where it approves), customer and cart fields, set-role action | View facades (N8) |
| YayLoyalty | Points and tiers | Points and tier fields, points-earned trigger, award-points action | Product / category pickers (D11) |

Matrix to prove: a grant step retried, a run replayed, two workflows on one event, a wholesale price and a YayPricing rule on one order, each plugin removed mid-run. Each cell checks: grant once, prices as the plugins compute them without YayMatik, run detail correct.

## Layers

| Layer | v1 (1.0) | Next |
|---|---|---|
| Steps | Triggers, actions, email capability, iframe editor | Actions that wait for an outside callback; actions that return lists (For each) |
| Data | Fields computed per object by callbacks; own data items; trigger facts | Fields core can query in bulk (D10) |
| Editor UI | Server-drawn fields, iframe returning one value | Search pickers, dependent options, a small repeater (D11) |
| Audience and time | Events only | Segments (D16) and broadcasts by cursor (D18) |
| Composition | Templates of one plugin + core | Pro multi-plugin templates (D7), feature directory |
| AI | Declarative catalog | Catalog → JSON Schema; per-step examples |
| Events | `yaymatik_fire` starts runs | Stored event timeline (D14) |

## Use cases checked

| Plugin | Fits v1 | Gap |
|---|---|---|
| YayMail, YayMagic | `email` action + `editor` iframe: design, Preview, Send test, merge tags, Sent / Skipped / Failed in run detail | Plugin side: embed mode, render by template id without globals. Double wrap (D17) |
| YaySMTP | Delivers whatever YayMatik sends through `wp_mail` | Delivery status back to the run |
| YayWholesale | Core Pro spend / order-count triggers + condition + own set-role action; role fields | Tier tables need a repeater or one branch per tier |
| YayLoyalty | Points and tier fields; award-points action; points-earned trigger | Product / category pickers are core-only (D11) |
| YayPricing | Order fields for applied rules | Where it stores applied rules is not documented **[Guess]** |
| Promo across plugins | YayLoyalty field as a condition, YayMagic email | No audience trigger (D16, D18); multi-plugin template is Pro (D7) |

## Decisions

| # | Decision | Status |
|---|---|---|
| D1 | Keep the public names: `YayMatik\Api`, `Registrar`, `Run` | Confirmed 2026-11-25 |
| D8 | New Pro and core steps use the public API where it is enough; a gap becomes a MINOR API addition; existing Pro modules move over gradually. Steps that need core internals (claims, sweeps) stay internal | Confirmed 2026-11-25 |
| D9 | Each Yay plugin owns its YayMatik extension in its own repo. YayMatik CI runs each extension against `develop` | Confirmed 2026-11-26; team meeting to agree |
| D15 | Scope in two groups: email stack (YayMail, YayMagic, YaySMTP), then customer value stack (YayPricing, YayWholesale, YayLoyalty). Order of the rest (YayReviews, YayReturns, YayBoost, YayCheckout, Brandy family, YayCurrency, YayExtra, YayAgentic) decided after group 2 | Confirmed 2026-11-26; team meeting to agree |
| D10 | Queryable customer data: a data field may declare the meta key (user, order or term meta) that stores it, so core can filter in SQL; callback-only fields work in runs but not in segments | Confirmed 2026-11-26 |
| D16 | YayMatik as the customer hub: tags, Shopify-style segments built on D10 fields, one shared consent check for every plugin's emails. Free vs Pro open | Open |
| D17 | Email: one action per plugin, as the 1.0 contract has it (not one Send email with a design slot). Every email YayMatik sends carries a mark (a header such as `X-YayMatik-Run`): YayMail and YayMagic skip wrapping a marked email, and YaySMTP links its delivery log to the run by it | Confirmed 2026-11-26 |
| D18 | Broadcasts: a saved cursor plus a send-rate setting instead of one run per customer up front; a daily check for customers who joined or left a segment | Open, after D16 |
| D11 | Grow the field schema (search pickers, dependent options, small repeater); keep the iframe; no React slots | Open |
| D12 | Public but not promoted at 1.0; promoted around v1.2 after the group 1 extensions pass | Open |
| D13 | PHPStan array-shape stubs, `wp yaymatik extension check`, an extension scaffold | Open |
| D14 | Event timeline deferred until "wait for event" or goals are planned | Open |
| D2 | A "Production layout" section in extension-api.md; the example stays one file | Open |
| D7 | Pro multi-plugin templates built on the public API | Open, after D8 |
