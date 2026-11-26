# Extensions roadmap

Where the Extension API goes after v1 ([extension-api.md](extension-api.md)). **Planned, not built.** Decisions are numbered as discussed. Plans are written on each recommendation; a decision marked *Recommended* is to be revisited before its plan is built.

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

**Proved** means a scenario test per cell of the group's matrix passes, in `pnpm test:php` and in `./run.sh check-ecosystem` on a real site, where each product's real plugin replaces its scaffold when installed (D21).

**Who builds what.** Lane A (Ecosystem Foundation) builds everything in YayMatik first: the core changes each group needs, one extension scaffold per product in `dev/ecosystem/<product>/` (its steps, fields, triggers and catalog templates, with step bodies behind a small product adapter), fake adapters for the tests, the scenario tests and the local check. Last, the lane copies each scaffold into its product's repo (one PR each). The product team writes the real adapter; the local check then runs their plugin in place of the scaffold.

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
| YaySMTP | Delivery and its log | Calls `yaymatik_email_status` with the mark when its log changes (D19); opens / clicks if it tracks them **[Guess]** | Sent / failed from WordPress's mail hooks without YaySMTP; delivery status in run detail |

Matrix to prove: each design source (core, YayMail, YayMagic) × YaySMTP on / off × the other designer on / off × designer plugin removed after the workflow was saved. Each cell checks: one email, one wrapper, unsubscribe link present, run detail correct.

## Group 2: customer value stack

| Plugin | Role in a workflow | Extension adds | Core adds |
|---|---|---|---|
| YayPricing | Applied rules and discounts as order data | Order and order-item fields (`yaypricing_rules`, `yaypricing_discount_total`) | — |
| YayWholesale | Wholesale role and approval | Triggers "request submitted", "approved", "rejected" (`yaymatik_fire` where it decides), customer and cart fields, set-role action | A per-step key so a grant runs once on retry (`Run::key()`) |
| YayLoyalty | Points and tiers | Points and tier fields, triggers points earned, tier reached, points expiring; award-points action | Same per-step key |

Matrix to prove: a grant step retried, a run replayed, two workflows on one event, a wholesale price and a YayPricing rule on one order, each plugin removed mid-run. Each cell checks: grant once, prices as the plugins compute them without YayMatik, run detail correct.

## v1.1 catalog (D22)

Six extensions and 21 templates, all planned and scaffolded once. Each product releases its extension when it is **ready**: its cells pass `./run.sh check-ecosystem` with its plugin installed (D21) and its release contains the extension. YayMatik shows a template only when every plugin it needs is active, so v1.1 waits for no product, and a product that is not ready hides its templates.

| # | Product | Template | Waits for |
|---|---|---|---|
| 1 | YayMail | Order paid → thank-you | |
| 2 | YayMail | Order completed → review request | |
| 3 | YayMail | Abandoned cart → recovery email | |
| 4 | YayMagic | Account created → welcome | |
| 5 | YayMagic | Customer inactive → win-back | |
| 6 | YayMagic | Nth order → milestone thank-you | |
| 7 | YaySMTP | Email delivery failed → alert the store team | |
| 8 | YaySMTP | Customer email bounced → tag the customer, stop marketing | Tags (D16) |
| 9 | YayWholesale | Wholesale request submitted → alert the store team | |
| 10 | YayWholesale | Approved → welcome with wholesale terms | |
| 11 | YayWholesale | Rejected → polite reply | |
| 12 | YayWholesale | Cart below the wholesale minimum → "Add $X more" | |
| 13 | YayLoyalty | Points earned → balance email | |
| 14 | YayLoyalty | Tier reached → coupon | |
| 15 | YayLoyalty | Points expiring soon → reminder | |
| 16 | YayLoyalty | Inactive customer with points → "Your N points are waiting" | |
| 17 | YayPricing | Large rule discount → alert the store team | |
| 18 | YayPricing | Order used rule X → follow-up cross-sell | |
| 19 | Pro: YayLoyalty + YayMagic | Tier reached → designed promo | D7 |
| 20 | Pro: YayWholesale + YayMail | Approved → set role, designed welcome | D7 |
| 21 | Pro: YaySMTP + YayMail | Order email failed → resend, then alert | D7 |

None of the 21 needs a search picker or a repeater: rule and tier choices are `options` lists (D11).

Plans: `.claude/plans/ecosystem/scaffolds.md` (1–18, fake adapters), `email-stack.md` (1–7), `value-stack.md` (9–18), `copy-scaffolds.md` (all six, to the product repos). Templates 19–21 are Pro (D7).

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
| D9 | Each Yay plugin owns its YayMatik extension in its own repo, starting from the scaffold YayMatik copies in | Confirmed 2026-11-26; team meeting to agree |
| D10 | Queryable customer data: a data field may declare the meta key (user, order or term meta) that stores it, so core can filter in SQL; callback-only fields work in runs but not in segments. First consumer is segments (D16), so not built in this lane | Confirmed 2026-11-26 |
| D15 | Scope in two groups: email stack (YayMail, YayMagic, YaySMTP), then customer value stack (YayPricing, YayWholesale, YayLoyalty). Order of the rest (YayReviews, YayReturns, YayBoost, YayCheckout, Brandy family, YayCurrency, YayExtra, YayAgentic) decided after group 2 | Confirmed 2026-11-26; team meeting to agree |
| D17 | Email: one action per plugin, as the 1.0 contract has it (not one Send email with a design slot). Every email YayMatik sends carries a mark: YayMail and YayMagic skip wrapping a marked email, and YaySMTP links its delivery log to the run by it | Confirmed 2026-11-26 |
| D22 | v1.1 catalog: 6 extensions, 21 templates, all scaffolded in YayMatik first, then copied to the product repos; each product releases its extension when ready | Confirmed 2026-11-26 |
| D23 | The Yay ecosystem moves from v1.2 into v1.1, released by readiness | Confirmed 2026-11-26 |
| D13 | Extension scaffold per product, built in YayMatik and copied to its repo (lane A's last row). PHPStan stubs and `wp yaymatik extension check`: recommend after the email stack | Scaffold confirmed 2026-11-26; rest Recommended |
| D19 | Delivery status: YaySMTP calls `do_action( 'yaymatik_email_status', $mark, $status )`; without YaySMTP core records sent / failed from `wp_mail_succeeded` / `wp_mail_failed`. Not: reading YaySMTP's log table | Recommended |
| D20 | The mark is a header `X-YayMatik-Run: <run id>-<step id>`; Send test uses `test`. Not: a global flag during the send | Recommended |
| D21 | No cross-repo CI for now. `./run.sh check-ecosystem [--wp-env]` runs the scenario cells on a real site, with each product's plugin in place of its scaffold when installed; `pnpm test:php` runs them on the fake adapters | Confirmed 2026-11-26 |
| D7 | Templates that combine plugins live in YayMatik Pro on the public API and show only when every plugin they need is active | Recommended |
| D16 | Customer hub: tags and the shared consent check in Free, segments in Pro | Recommended |
| D11 | Search pickers, dependent options and a small repeater; keep the iframe; no React slots. No v1.1 template needs them, so not in this lane | Recommended, revisit (no consumer) |
| D18 | Broadcasts: a saved cursor plus a send-rate setting instead of one run per customer up front; a daily check for customers who joined or left a segment | Recommended, after D16 |
| D12 | Public but not promoted at 1.0; promoted once the email stack is ready | Recommended |
| D14 | Event timeline deferred until "wait for event" or goals are planned | Recommended |
| D2 | A "Production layout" section in extension-api.md; the example stays one file | Recommended |
