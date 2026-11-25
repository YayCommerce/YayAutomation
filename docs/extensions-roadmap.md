# Extensions roadmap

Where the Extension API goes after v1 ([extension-api.md](extension-api.md)). **Planned, not built.** Decisions are numbered as discussed; open ones carry a recommendation.

## Approach

YayMatik owns the runtime, data, UI shell and contract. Plugins add domain logic, domain data and domain UI, as declarations core can check, draw, document and hand to AI. Escape hatches stay narrow and same-origin.

1. **Core owns the run.** Queue, waits, retries, claims, versions, history, privacy. Plugins give step bodies (`run` / `build`) and events (`yaymatik_fire`).
2. **Declared, not coded.** Every trigger, action, field and template is an array with labels and types: core validates it, draws its form, generates docs from it, and AI reads it (v2.0).
3. **Additive.** New keys, field types and `supports()` features in MINOR releases; breaking changes only behind `yaymatik_register_v2`.
4. **Degrade softly.** A broken or missing plugin is a diagnostic or a Skipped step, never a fatal or a cancelled run.
5. **Privacy by construction.** People are reached through ids; plugins declare their own subjects.

## Layers

| Layer | v1 (1.0) | Next |
|---|---|---|
| Steps | Triggers, actions, email capability, iframe editor | Actions that wait for an outside callback; actions that return lists (For each) |
| Data | Fields computed per object by callbacks; own data items; trigger facts | Fields core can query in bulk (D10) |
| Editor UI | Server-drawn fields, iframe returning one value | Search pickers, dependent options, a small repeater (D11) |
| Audience and time | Events only | Schedule + "customers matching…" fan-out (D4) |
| Composition | Templates of one plugin + core | Pro multi-plugin templates (D7), feature directory |
| AI | Declarative catalog | Catalog → JSON Schema; per-step examples |
| Events | `yaymatik_fire` starts runs | Stored event timeline (D14) |

## Use cases checked

| Plugin | Fits v1 | Gap |
|---|---|---|
| YayMail, YayMagic | `email` action + `editor` iframe: design, Preview, Send test, merge tags, Sent / Skipped / Failed in run detail | Plugin side: embed mode, render by template id without globals |
| YayWholesale | Core Pro spend / order-count triggers + condition + own set-role action; role fields | Tier tables need a repeater or one branch per tier |
| YayLoyalty | Points and tier fields; award-points action; points-earned trigger | Product / category pickers are core-only (D11) |
| YayReview | Core `review_posted` + rating condition + own blacklist action | Own trigger if it saves reviews outside comment hooks |
| Promo across plugins | YayLoyalty field as a condition, YayMagic email | No schedule-to-audience trigger (D4, D10); multi-plugin template is Pro (D7) |

## Decisions

| # | Decision | Status |
|---|---|---|
| D1 | Keep the public names: `YayMatik\Api`, `Registrar`, `Run` | Confirmed 2026-11-25 |
| D8 | New Pro and core steps use the public API where it is enough; a gap becomes a MINOR API addition; existing Pro modules move over gradually. Steps that need core internals (claims, sweeps) stay internal | Confirmed 2026-11-25 |
| D10 | Queryable customer data. Recommend: a data field may declare where its value is stored, so core can filter in SQL; callback-only fields work in runs but not in audiences | Open |
| D9 | Connectors live in each plugin's repo; YayMatik CI runs them against develop | Open |
| D11 | Grow the field schema (search pickers, dependent options, small repeater); keep the iframe; no React slots | Open |
| D12 | Public but not promoted at 1.0; promoted around v1.2 after 2–3 Yay connectors | Open |
| D13 | PHPStan array-shape stubs, `wp yaymatik extension check`, a connector scaffold | Open |
| D14 | Event timeline deferred until "wait for event" or goals are planned | Open |
| D2 | A "Production layout" section in extension-api.md; the example stays one file | Open |
| D3 | Connector order: YayMagic, YayMail, YayLoyalty, YayWholesale, YayReview | Open, after D9 |
| D4 | Schedule + "customers matching…" trigger before the full V11.1–V11.3 | Open, after D10 |
| D7 | Pro multi-plugin templates built on the public API | Open, after D8 |
