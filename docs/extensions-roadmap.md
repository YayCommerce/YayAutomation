# Extensions roadmap

Where the Extension API goes after v1 ([extension-api.md](extension-api.md)). **Planned, not built.** Decisions are numbered as discussed. Plans are written on the confirmed decisions; a decision marked *Recommended* is not needed by any plan yet.

**Words.** One word per thing, here and in the lane's plans:

- **Product:** a Yay plugin, such as YayMail.
- **Module:** a group of steps, data and templates. Core, Pro and extensions each register modules.
- **Extension:** a plugin outside YayMatik that registers a module through the Extension API. One per product.
- **Connector:** an outside service only (Slack, Sheets, webhooks: V12.4–12.5 in [ROADMAP-dev.md](ROADMAP-dev.md)).
- **Connection:** a connector's site-level account (an API key or OAuth token), entered once and picked by many steps. Never a step's `settings`.
- **Scaffold:** an extension's code as YayMatik builds it in `dev/ecosystem/<product>/`, later copied to the product's repo.
- **Adapter:** the part of a scaffold that calls the product. YayMatik's scaffolds carry a **fake adapter**; the product team writes the **real adapter**.
- **Stack:** the email stack or the value stack.
- **Matrix, cell:** a stack's list of cases to prove; a cell is one case, with one test.
- **Ready:** an extension whose cells pass with the real adapter, in a product release.

## Scope

YayMatik solves and proves that products work together safely in one workflow, one stack at a time:

1. **Email stack:** YayMail, YayMagic, YaySMTP.
2. **Value stack:** YayPricing, YayWholesale, YayLoyalty.

The other products follow once both stacks pass (D15).

**Safely** means, for every mix of these products active or missing:

- One email step sends one email, wrapped once, logged once.
- A grant (role, points, discount) happens once per run, even when a step retries.
- A missing or broken product gives a Skipped step and a diagnostic, never a fatal or a cancelled run.
- The shopper's request does no workflow work beyond recording the event.
- Every customer email passes the same consent check.

**Proved** means the test of every cell in the stack's matrix passes, in `pnpm test:php` and in `./run.sh check-ecosystem` on a real site, where an installed product replaces its scaffold (D21).

**Who builds what.** Lane A (Ecosystem Foundation) builds everything in YayMatik first: the six scaffolds with fake adapters, the core changes each stack needs, the cell tests and the local check. Last, the lane copies each scaffold into its product's repo (one PR each). The product team writes the real adapter; the local check then runs the product in place of the scaffold.

## Approach

YayMatik runs inside WordPress and WooCommerce; it does not own the runtime. It owns its state (runs, claims, cursors) and borrows the clock (WP-Cron / Action Scheduler), the database, `wp_mail` and WooCommerce hooks. So:

- **Batch everything.** Work goes to background jobs in chunks, never one job per customer up front.
- **Never slow the shopper.** A hook on the shopper's request records the event and returns.
- **Light up by capability.** A feature shows only when the plugin it needs is active and new enough.
- **State timing honestly.** The UI says "within about N minutes", not "now", when the clock is WP-Cron.

Within that, core owns the run and the contract; plugins add domain logic, data and UI as declarations:

1. **Core owns the run.** Queue, waits, retries, claims, versions, history, privacy. Plugins give step bodies (`handle` / `build`) and events (`yaymatik_fire`).
2. **Declared, not coded.** Every trigger, action, field and template is an array with labels and types: core validates it, draws its form, generates docs from it, and AI reads it (v2.0).
3. **Additive.** New keys, field types and `Step` methods in MINOR releases (D26: no `supports()`; `requires_api` and `api_version()` say what's there); breaking changes only behind `yaymatik_register_v2`.
4. **Degrade softly.** A broken or missing plugin is a diagnostic or a Skipped step, never a fatal or a cancelled run.
5. **Privacy by construction.** People are reached through ids; plugins declare their own subjects.

## Email stack

| Product | Role in a workflow | Extension adds | Core adds |
|---|---|---|---|
| YayMail | Design for order emails | Template kind not bound to a WC email, embedded editor, render by template id + order, skip wrapping a marked email | Mark on every email it sends (D17) |
| YayMagic | Design for any email, with or without an order | Embedded editor, render by template id from `$step`, no globals in shortcodes, skip wrapping a marked email | Mark on every email it sends (D17) |
| YaySMTP | Delivery and its log | Calls `yaymatik_email_status` with the mark when its log changes (D19); opens / clicks if it tracks them **[Guess]** | Sent / failed from WordPress's mail hooks without YaySMTP; delivery status in run detail |

Matrix: each design source (core, YayMail, YayMagic) × YaySMTP on / off × the other design source on / off × the design source's product removed after the workflow was saved. Each cell checks: one email, one wrapper, unsubscribe link present, run detail correct.

## Value stack

| Product | Role in a workflow | Extension adds | Core adds |
|---|---|---|---|
| YayPricing | Applied rules and discounts as order data | Order and order-item fields (`yaypricing_rules`, `yaypricing_discount_total`) | — |
| YayWholesale | Wholesale role and approval | Triggers "request submitted", "approved", "rejected" (`yaymatik_fire` where it decides), customer and cart fields, set-role action | A per-step key so a grant runs once on retry (`Step::key()`) |
| YayLoyalty | Points and tiers | Points and tier fields, triggers points earned, tier reached, points expiring; award-points action | Same per-step key |

Matrix: a grant step retried, a run replayed, two workflows on one event, a wholesale price and a YayPricing rule on one order, each product removed mid-run. Each cell checks: grant once, prices as the products compute them without YayMatik, run detail correct.

## v1.1 catalog (D22)

Six extensions and 21 templates, all planned and scaffolded once. Each product releases its extension when it is ready (D21). YayMatik shows a template only when every product it needs is active, so v1.1 waits for no product, and an extension that is not ready shows no templates.

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

Plans in `.claude/plans/ecosystem/`: `extension-scaffolds.md` (six scaffolds, templates 1–18), `email-stack.md`, `yaymagic-editor.md`, `value-stack.md`, `copy-scaffolds.md`. Templates 19–21 are Pro (D7).

## Layers

| Layer | v1 (1.0) | Next |
|---|---|---|
| Steps | Triggers, actions, email capability, iframe editor | Actions that wait for an outside callback; actions that return lists (For each) |
| Data | Fields computed per object by callbacks; own data items; trigger facts | Fields core can query in bulk (D10) |
| Editor UI | Server-drawn fields, iframe returning one value | Search pickers, dependent options, a small repeater (D11) |
| Audience and time | Events only | Segments (D16) and broadcasts by cursor (D18) |
| Composition | Templates of one product + core | Pro templates that combine products (D7), feature directory |
| AI | Declarative catalog | Catalog → JSON Schema; per-step examples |
| Events | `yaymatik_fire` starts runs | Stored event timeline (D14) |

## Use cases checked

| Product | Fits v1 | Gap |
|---|---|---|
| YayMail, YayMagic | `email` action + `editor` iframe: design, Preview, Send test, merge tags, Sent / Skipped / Failed in run detail | Extension side: embed mode, render by template id without globals. Double wrap (D17) |
| YaySMTP | Delivers whatever YayMatik sends through `wp_mail` | Delivery status back to the run |
| YayWholesale | Core Pro spend / order-count triggers + condition + own set-role action; role fields | Tier tables need a repeater or one branch per tier |
| YayLoyalty | Points and tier fields; award-points action; points-earned trigger | Product / category pickers are core-only (D11) |
| YayPricing | Order fields for applied rules | Where it stores applied rules is not documented **[Guess]** |
| Promo across products | YayLoyalty field as a condition, YayMagic email | No audience trigger (D16, D18); a template that combines products is Pro (D7) |

## Decisions

| # | Decision | Status |
|---|---|---|
| D1 | Keep the public names: `YayMatik\Api`, `Registrar`; `Run` became `Step` (D26) | Confirmed 2026-11-25; amended 2026-11-27 |
| D8 | New Pro and core steps use the public API where it is enough; a gap becomes a MINOR API addition; existing Pro modules move over gradually. Steps that need core internals (claims, sweeps) stay internal | Confirmed 2026-11-25 |
| D9 | Each product owns its extension in its own repo, starting from the scaffold YayMatik copies in | Confirmed 2026-11-26; team meeting to agree |
| D10 | Queryable customer data: a data field may declare the meta key (user, order or term meta) that stores it, so core can filter in SQL; callback-only fields work in runs but not in segments. First consumers are Get data filters (V11.2) and segments (D16); built with V11.2, not in this lane | Confirmed 2026-11-26; amended 2026-11-27 |
| D15 | Scope in two stacks: email stack (YayMail, YayMagic, YaySMTP), then value stack (YayPricing, YayWholesale, YayLoyalty). Order of the rest (YayReviews, YayReturns, YayBoost, YayCheckout, Brandy family, YayCurrency, YayExtra, YayAgentic) decided after the value stack | Confirmed 2026-11-26; team meeting to agree |
| D17 | Email: one action per extension, as the 1.0 contract has it (not one Send email with a design slot). Every email YayMatik sends carries a mark: YayMail and YayMagic skip wrapping a marked email, and YaySMTP links its delivery log to the run by it | Confirmed 2026-11-26 |
| D22 | v1.1 catalog: 6 extensions, 21 templates, all scaffolded in YayMatik first, then copied to the product repos; each product releases its extension when ready | Confirmed 2026-11-26 |
| D23 | The Yay ecosystem moves from v1.2 into v1.1, released by readiness | Confirmed 2026-11-26 |
| D13 | A scaffold per product, built in YayMatik and copied to its repo (row `copy-scaffolds`), with `wp yaymatik extension check` and PHPStan stubs (row `extension-check`) | Confirmed 2026-11-27 |
| D19 | Delivery status: YaySMTP calls `do_action( 'yaymatik_email_status', $mark, $status )`; without YaySMTP core records sent / failed from `wp_mail_succeeded` / `wp_mail_failed`. Not: reading YaySMTP's log table | Confirmed 2026-11-26 |
| D20 | The mark is a header `X-YayMatik-Mark` (value: D28); Send test uses `test`. Not: a global flag during the send | Confirmed 2026-11-26; amended 2026-11-27 |
| D21 | No cross-repo CI for now. `./run.sh check-ecosystem [--wp-env]` runs the cell tests on a real site, with an installed product in place of its scaffold; `pnpm test:php` runs them on the fake adapters | Confirmed 2026-11-26 |
| D7 | Templates that combine products live in YayMatik Pro on the public API and show only when every product they need is active | Confirmed 2026-11-26 |
| D16 | Customer hub: tags and the shared consent check in Free, segments in Pro. Tags in v1.1 (V11.10), stored so a segment can query them; segments come later with broadcasts (D18) | Confirmed 2026-11-26; amended 2026-11-27 |
| D24 | An extension's editor opens in the v1 full-screen modal iframe, as is (no inline panel mode) | Confirmed 2026-11-26 |
| D25 | YayMatik loads `dev/ecosystem/*/` only with `YAYMATIK_IS_DEVELOPMENT` on, on `plugins_loaded` priority 20; `YAYMATIK_DEV_ECOSYSTEM_OFF` leaves products out (no other skip rule: scaffolds live only until copy-scaffolds); `./run.sh ecosystem-fire <trigger>` fires a trigger by hand. Not: a symlink or a wp-env mapping | Confirmed 2026-11-26 |
| D26 | API names settled before any extension ships (row `api-names`): `Run` → `Step`, action `run` → `handle`, step `fields` → `settings`, action `requires` → `needs`, trigger `sample` → `sample_facts`, template `name` → `label`, template `category` → `library_category`, `supports()` removed (the API is the same in Free and Pro; versions answer what's there). Kept: `module()` / `Module`. No stored id, key, option or table changes: 1.0 is released | Confirmed 2026-11-27 |
| D27 | `Step::is_test()` is true whenever a step must do nothing real (Send test now; test run and dry run in V11.5); actions with side effects skip them then | Confirmed 2026-11-27 |
| D28 | The mark and `Step::key()` are the run-step row's: one per loop item (V11.1), the same across attempts and replays of it (V11.7). The key is the row id plus its run's start (`StepKey`), so it is never reused after the tables start over (reinstall, reset, restored backup); a replay keeps the run's `started_at` | Confirmed 2026-11-27; amended 2026-11-27 (Review) |
| D29 | Retries (V11.7): `fail( $reason, retry: true )` marks a passing error (timeout) apart from a permanent one; built with V11.7 | Confirmed 2026-11-27 |
| D30 | Connectors on the Extension API (paper proof below): two MINOR additions, connections and remote options, built in v1.2 | Confirmed 2026-11-27 |
| D11 | Search pickers, dependent options and a small repeater; keep the iframe; no React slots. No v1.1 template needs them, so not in this lane | Confirmed 2026-11-26 |
| D18 | Broadcasts: a saved cursor plus a send-rate setting instead of one run per customer up front; a daily check for customers who joined or left a segment | Recommended, after D16 |
| D12 | Public but not promoted at 1.0; promoted once the email stack is ready | Recommended |
| D14 | Event timeline deferred until "wait for event" or goals are planned | Recommended |
| D2 | A "Production layout" section in extension-api.md; the example stays one file | Recommended |

## Connector proof (paper, D30)

A Slack "Send message" step written against the API as it will be after D26:

```php
$m->action( 'slack_send_message', [
    'settings' => [
        'connection' => [ 'type' => 'select', 'options' => /* gap 1 */ ],
        'channel'    => [ 'type' => 'select', 'options' => /* gap 2 */ ],
        'text'       => [ 'type' => 'textarea', 'variables' => true ],
    ],
    'handle' => [ $this, 'send' ], // a timeout: $step->fail( $reason, retry: true ) (D29)
] );
```

| Gap | What v1.2 adds (MINOR) |
|---|---|
| 1. A site-level account, entered once | A module declares a connection type (its credential fields); core stores connections and offers them as a `connection` setting |
| 2. A list from the remote service (channels, tabs) | Remote options: fetched through the module, cached, searched (with D11's search pickers) |

Not gaps: outbound errors (D29); an inbound webhook is a core trigger with an endpoint and a secret, not the API.

