# Plan: Lite / Pro split (YayMatik 1.0.0)

Status: written 2026-11-16 by Claude (advisor), decisions agreed with Jack the same day. Source: `YayMatik-1.0-Lite-Pro.md` (leader proposal, approved split) and the Slack summary. Base: **develop after PR 034b is merged** (Extension API ships in 1.0, leader approved). One branch, one PR, one-run mode.

- Branch `jack/feature/036-lite-pro-split` from develop (HEAD `1a58c38`, PR #42 merge of 034b; 034a = #41 `06f2857`).
- Target: 2 days of work. Priority if time runs out: CP1 → CP2 → CP4 → CP5 → CP3 → CP6 → CP7. **Lite is not submitted until CP3 and CP6 pass.**
- **One repo (B1 closed)**: Lite and Pro stay in `YayCommerce/YayAutomation`. The Lite zip check (D14) is the only guard keeping Pro code out of WordPress.org: it runs in every free `release.sh` and the build fails if it finds anything.
- Updated Nov 16, 22:40: U1–U5 + folder layout (D0) folded in; step / field ids checked against 034b `32823b1`, all match.
- Updated Nov 17, 10:00 (D16, Jack): license pattern source = YayCurrency (local copy on Jack's Mac), temporary item id 7761 (YayCurrency); expired license keeps Pro features (admin-shell `LicenseState::allows_features()`); Guard 2 = Pro-side filter (admin-shell registers the EDD updater unconditionally in `LicenseHandler::auto_update`).
- Updated Nov 17, 10:20 (paths checked on develop `1a58c38`): e2e lives in `dev/e2e/specs/`; template folders mix Lite and Pro templates (D11); D18 commits reviewed by the advisor (no blocker).

## Goal

One repo builds two plugins: **YayMatik** (Lite, WordPress.org) and **YayMatik Pro** (`yaymatik-pro`, installed instead of Lite). The Lite zip contains no Pro code. A store moving from Pro to Lite loses no data and never runs a workflow half-way.

## The split (source of truth for every CP)

| | Lite | Pro adds |
|---|---|---|
| Triggers | order_created, order_status_changed, order_paid, order_refunded, order_note_added, cart_abandoned, checkout_abandoned, customer_account_created, review_posted | customer_inactive, customer_order_count_reaches, customer_total_spend_reaches |
| Actions | send_email, send_internal_email, generate_coupon, wait, add_order_note, change_order_status | wait_until, resend_wc_email, update_order_meta, update_customer_meta, change_customer_role |
| Customer fields | email, first_name, last_name, full_name, phone, billing_country, is_first_order, is_guest, unsubscribed | paid_order_count, total_spent, last_order_date, registered_date, role, purchased_product_ids, meta |
| Order fields | all others | discount_total, shipping_total, refunded_total, created_via, currency, has_cross_sells, has_upsells, cross_sell_products, upsell_products, meta |
| Cart, review, refund, order item, site fields | all | — |
| Condition | one flat list, match all / any, fixed values, relative dates | groups (one level), compare with another variable (`valueType: variable`), custom field rules (meta fields) |
| Templates (8 / +10) | cart-reminder, checkout-reminder, checkout-reminder-coupon, first-order-thank-you-discount, first-order-thank-you-free-shipping, review-request, welcome-email, high-value-order-alert | cart-series, cart-series-coupon, cross-sell-first-time-buyers, cross-sell-repeat-buyers, cross-sell-coupon, upsell-after-30-days, review-request-reminder, welcome-series, win-back-discount, win-back-free-shipping |
| Settings | track carts, capture guest emails, cart retention 7–60 days, run retention 7–90 days, unsubscribe text, health check | abandon minutes 15–1440 (shown), cart retention to 365, run retention to 730 |

Check the field ids against `DataItems/*::define()` and the template ids against `templates/*/index.ts` in CP0; stop and report if one differs or a template can't be matched to a row above.

## Decisions

- **D0 Pro folder layout (rule for every CP)**: each Pro folder mirrors the Lite path, so the build excludes whole folders and a reviewer sees the edition from the path.

  | Kind | Lite path | Pro path |
  |---|---|---|
  | PHP | `includes/Workflow/Triggers/CustomerInactive.php` | `includes/Pro/Workflow/Triggers/CustomerInactive.php` (namespace `YayMatik\Pro\Workflow\Triggers`) |
  | PHP entry | — | `includes/Pro/Boot.php` |
  | JS steps | `apps/admin-page/src/routes/workflows/steps/actions/wait-until/` | `apps/admin-page/src/pro/routes/workflows/steps/actions/wait-until/` |
  | JS templates | `src/routes/workflows/templates/<folder>/index.ts` | `src/pro/routes/workflows/templates/<same folder>/index.ts` |
  | JS edition entry | `src/lite/index.ts` (empty lists + teaser) | `src/pro/index.ts` |
  | PHP tests | `tests/Unit/...` | `tests/Unit/Pro/...` (mirrors) |
  | JS tests | beside the code | beside the code, inside `src/pro/` |
  | e2e | `dev/e2e/specs/...` | `dev/e2e/specs/pro/...` |

  Dependency direction: Pro may import Lite code (helpers, base classes); Lite never imports Pro (only the exceptions below).
  Naming: a Pro class that extends a Lite class takes the Lite name with `Pro` before its type suffix, as `YayMatikProModule` already does (`CustomerProData`, `OrderProData`, `CriteriaPro` when there is no suffix). A Pro-only class keeps its own name (`CustomerInactive`, `WaitUntil`).
  Build: `.distignore` drops `/includes/Pro` (exists); the Lite JS build never imports `src/pro` (D4); Lite test runs exclude `tests/Unit/Pro` and `src/pro/**` by path. **Any Pro behaviour outside these folders is a review blocker.** The only allowed Lite-side Pro mentions: the one `Pro\Boot` call (D3), `ProCatalog` (names only, D5), the Lite teaser in `src/lite/` (D13).
- **D1 Two folders hold all Pro code**: `includes/Pro/` (namespace `YayMatik\Pro\`) and `apps/admin-page/src/pro/`, laid out as D0. Lite code never names a Pro class except the one `Pro\Boot` call (D3).
- **D2 Pro package**: `release.sh pro` builds folder `yaymatik-pro/` with main file `yaymatik-pro.php` (Plugin Name "YayMatik Pro", `Update URI` set so WordPress.org never updates it), same text domain `yaymatik`, same options and tables (moving Lite ↔ Pro keeps all data). Both active: the existing `includes/Fallback.php` guard deactivates whichever loads second; `yaymatik-pro/` sorts before `yaymatik/`, so Lite is the one deactivated. Prove it in `check-plugin-lifecycle`. License + updater: see D16.
- **D3 PHP edition switch**: `ModuleRegistry::classes()` drops its `class_exists( YayMatikProModule::class )` switch and always lists the Lite modules. `Pro\Boot::register()` swaps in the Pro modules (`includes/Pro/Workflow/Modules/`, exist) through the internal `yaymatik_modules` filter, **only when the license allows features** (D16). Pro modules extend the Lite modules and `array_merge( parent::triggers(), [ … ] )` etc. Other Pro wiring (InactiveSweep, settings ranges) lives in `includes/Pro/Boot.php` with two methods: `register()` (adds Pro hooks, filters, ranges; runs early) and `boot()` (starts jobs such as `InactiveSweep`; runs on `init`). `YayMatik::initialize()` calls both when the class exists. These names match plan 037 P1, where `Pro\Boot` becomes `ProServiceProvider` by a rename only. No `is_pro()` anywhere.
- **D4 JS edition switch**: Vite alias `@edition` → `src/pro/index.ts` or `src/lite/index.ts`, picked by env `YAYMATIK_EDITION` (`pro` default in dev, `release.sh` sets it per edition and builds once per zip). The edition module exports the extra steps, templates, criteria features, settings fields and (Lite only) the teaser. `steps/index.ts` and `templates/index.ts` add `@edition`'s lists to the core ones.
- **D5 Lite knows Pro only by name**: `includes/Workflow/Core/Definition/ProCatalog.php` (Lite) lists Pro step ids with kind, label and outputs, Pro data field keys, and Pro condition features. Labels and ids only, no behaviour. `ProCatalog::missing()` = the catalog minus what is registered now, so in the Pro build it is empty and every rule below is a no-op without an edition check.
- **D6 Needs Pro (agreed)**: a workflow *needs Pro* when it uses anything in `ProCatalog::missing()`: a node whose step is a Pro step, a condition rule on a Pro field, a group, a `variable` comparison, a meta rule, or an email (subject, body, internal email) using a Pro merge tag (`{{customer.total_spent}}`, `{{order.cross_sell_products}}`, …). One function `ProUsage::of( array $graph ): array` (reasons) decides it, used by:
  - Validator: issue `needs_pro` (blocks Activate / Update, like `missing_plugin`).
  - Dispatcher: never starts a run for such a workflow (stricter than 034 D6 on purpose: a skipped condition would mean "true" and email the wrong customers).
  - Executor: a run already waiting that reaches a node needing Pro stops there with reason "Needs YayMatik Pro." Use an existing run / step status; no new status, no DB change.
  - Save: Lite keeps the stored Pro config untouched (034 D6 already keeps unknown nodes that match the stored graph; extend it to condition rules and email config). Back on Pro, the workflow works unchanged.
  - UI: Pro step tiles reuse 034's `inactiveSteps()` path (greyed, read-only, plugin label "YayMatik Pro"); a condition or email needing Pro is drawn read-only with "Needs YayMatik Pro". Health check lists these workflows next to `missing_plugin`.
- **D7 Settings (agreed: no read change)**: stored values keep working after a move to Lite, exactly as on Pro, including abandon minutes. `Settings::RANGES` stays the full range for reading. Lite: abandon minutes is not shown; the two retention fields are shown with the stored value and Lite's max is checked only when the owner saves a new value (`Settings::update()` checks the edition's range; Pro widens it from `Pro\Boot`). `saveSettings` already sends changed keys only, so an untouched 365 never fails a Lite save.
- **D8 Customer inactive's sweep**: `InactiveSweep` (`includes/Workflow/Core/Runtime/InactiveSweep.php`) and its health check row move to Pro (`Pro\Boot`). Lite unschedules a left-over `InactiveSweep::HOOK` once (cheap check, e.g. in the daily cleanup; the hook name stays as a string in Lite), so Action Scheduler doesn't log a failed action every day.
- **D9 Data items**: `CustomerData` / `OrderData` keep the Lite fields; `Pro\…\CustomerProData` / `OrderProData` extend them and add the Pro fields; the Pro modules return the Pro classes (same item ids). Order's `cross_sell_products` / `upsell_products` are Pro; cart's and site's product grids (`ProductGrid::newest()`) stay Lite.
- **D10 Criteria**: Lite `Criteria` = flat list, fixed values. `Pro\…\CriteriaPro extends Criteria` adds groups, `variable` and meta rules (same step id, `Criteria::ID`). The criteria `Form` reads its features from `@edition`: no "Add group", no variable toggle, no custom-field rule in Lite.
- **D11 Templates**: today's folders mix editions (e.g. `abandoned-cart/` holds `cart-reminder` = Lite and `cart-series`, `cart-series-coupon` = Pro; `review-request/` and `welcome/` are mixed too; `cross-sell-upsell/` and `win-back/` are all Pro). Move each Pro template definition to `src/pro/routes/workflows/templates/<same folder>/index.ts`; shared helpers used by a Lite template stay in the Lite folder and Pro imports them; a folder left with no Lite template moves whole. A test proves each Lite template validates with the Lite registry only. 034 plugin templates that use a Pro step or field are hidden in Lite by the existing rule (GraphSchema error / unknown variable hides a template, 034 M10); add a test.
- **D12 Extension API core list**: `docs/extension-api-core.md` and `tests/fixtures/extension-api-core.json` gain a tier (`lite` / `pro`) per step and data key; `CoreStepsTest` checks both editions. Docs say Pro entries exist only with YayMatik Pro. Do this before release: removing an entry later is a MAJOR change.
- **D13 Teaser (Lite only, `src/lite/`)**: one "PRO" group at the end of the template library and at the end of the trigger / action pickers, opening the upgrade URL (one constant). No disabled controls, no lock icons, no AI box.
- **D14 Lite zip check** `dev/scripts/lib/check-lite-zip.sh`, run by `release.sh` for the free edition: fails if the zip has `includes/Pro`, or `assets/dist` contains a Pro step id, a Pro template id or `yaymatik-pro`. The list comes from `ProCatalog` + the Pro template ids (one source, no hand copy).
- **D15 Tests in both editions**: `YAYMATIK_EDITION=lite` makes the PHPUnit bootstrap prepend an autoloader that refuses `YayMatik\Pro\` (so `class_exists` is false) and makes Vitest use the Lite alias. Add `pnpm test:lite` and `pnpm test:php:lite`; `check-all` runs PHPUnit in both editions. e2e: Pro as today, plus the Lite smoke (create from a Lite template, activate, run). Because Pro features load only with an active license (D16), the Pro test runs (PHPUnit, Vitest, wp-env, e2e) set a fake active license in setup (the option values `is_licensed()` reads); PHPUnit tests prove: no key → Lite; `disabled` → Lite; expired → still Pro.
- **D16 Pro license (pattern = YayCurrency; Jack, Nov 17)**: read the Pro license code of YayCurrency at `/Users/imac/Sites/wp/wp-content/plugins/YayCurrency/` (read-only; its license adapter, where it calls `AdminShell` / `enable_license`, its `is_licensed()`, and its release script) and follow that pattern. Develop's bundled admin-shell API (checked Nov 17): `License\Contracts\LicenseConfigAdapter`, `AdminShell::enable_license()`, `License\LicenseState::allows_features( $slug )` / `allows_updates( $slug )`. If YayCurrency's admin-shell API differs from the one bundled in YayMatik, follow YayMatik's bundled one and report the difference.
  - `includes/Pro/YayMatikProLicenseAdapter.php` implements `LicenseConfigAdapter`: menu methods as `YayMatikMenuAdapter`, plus slug `yaymatik-pro`, name "YayMatik Pro", version, `YAYMATIK_FILE`, `get_item_id()`, store URL `https://yaycommerce.com/`, store link; and a static `is_licensed()` = `LicenseState::allows_features( 'yaymatik-pro' )`.
  - **Expiry**: follow admin-shell: Pro features stop only when no key was activated on this site or the key is `disabled`. An expired key keeps Pro features and loses updates / support only (`allows_updates`). Same behaviour as other YayCommerce Pro plugins.
  - `Pro\Boot::register()` always registers this adapter instead of `YayMatikMenuAdapter` (so the Licenses page exists), then returns early when not licensed. Not licensed = the site runs as Lite; workflows using Pro parts become "Needs YayMatik Pro" through D5 / D6 with no extra code.
  - JS: Pro code localizes `pro: { licensed: bool }`; `src/pro/index.ts` returns no extra items when not licensed. No teaser in the Pro build.
  - The free build keeps removing admin-shell `src/License` (`release.sh` already does).
  - **Item id (temporary)**: `7761` = YayCurrency, only to prove activation. One constant `YayMatikProLicenseAdapter::ITEM_ID = 7761` with `// TODO(036): temporary id of YayCurrency (7761); replace with the YayMatik Pro id before release.`
  - Guard 1: `release.sh pro` fails while that TODO is present, except with `--rc` (test builds).
  - Guard 2: admin-shell registers `EDD_SL_Plugin_Updater` unconditionally in `LicenseHandler::auto_update` (`admin_init`), so it can't be skipped there. While the TODO is present, Pro code (in `includes/Pro/`, wired by `Pro\Boot::register()`, one flag next to the TODO constant, e.g. `ITEM_ID_IS_TEMPORARY = true`) removes the `yaymatik-pro` basename from `site_transient_update_plugins` (`response`; keep it in `no_update`) and returns `$result` unchanged from `plugins_api` for slug `yaymatik-pro`. Result: no Update button, no "View details" of YayCurrency, auto-update can't pull YayCurrency. Do not edit `vendor-prefixed`. Test: with the flag on, a transient containing a `yaymatik-pro` response comes back without it.

## Checkpoints

| CP | Work | Done when |
|---|---|---|
| CP0 | Branch from develop (034b merged). Confirm develop HEAD is `1a58c38` (or list what came after). List `git log --oneline 32823b1..1a58c38` in the report (D18, already reviewed). Run lint, lint:php, test, test:php, build, `check-all --wp-env`, e2e. (`check-all --wp-env-min` from a dev checkout is a known failure, plan 035: don't fix it here.) Check the split table (fields, steps, template ids) against the code. | Baseline recorded in the PR text; mismatches reported |
| CP1 | D1–D4, D14, D15, D16 plumbing; no feature moves. | Both zips build; Lite zip check passes; tests green in both editions; Pro zip installs as `yaymatik-pro`, Lite deactivates when both are on; license activates with 7761; no Update / View details offered for yaymatik-pro |
| CP2 | 3 triggers, 5 actions (PHP to `includes/Pro`, JS to `src/pro`, as D0), including their abstract base `CustomerMilestone` (parent of the two milestone triggers); `CustomerStats` stays Lite (shared storage). D8. | Lite pickers show 9 / 6; Pro 12 / 11; zip check finds no Pro step id in Lite |
| CP3 | D9, D10; Pro merge tags out of Lite's variable picker. | Lite condition form has no group / variable / custom field; Pro unchanged |
| CP4 | D11, D12. | Lite library 8, Pro 18; core list has tiers; CoreStepsTest green in both |
| CP5 | D7. | Lite shows 2 retention fields, no abandon field; values stored on Pro keep working on Lite (test) |
| CP6 | D5, D6. | `check-edition.sh`: on Pro create 4 workflows (Pro trigger, Pro action, Pro condition, Pro merge tag) + 1 waiting run; switch to Lite: none starts, none activates, the waiting run stops with the reason, saving keeps config; back to Pro: all work |
| CP7 | D13. | Teaser shows in Lite only, opens the upgrade URL |
| CP8 | readme.txt features = Lite; docs; mark `yaymatik_modules`, `yaymatik_trigger_refs`, `yaymatik_trigger_provides`, `yaymatik_data_item_{id}_fields` `@internal` in their docblocks; fix `docs/workflow-core-decisions.md` (~line 345: other plugins use `yaymatik_register`, not `yaymatik_modules`); `check-all` both editions; e2e; Plugin Check on the Lite zip; `.pot` last. | All green; PR text with results and browser steps |

## Rules for Claude Code

One-run mode, commit locally per CP. No push, no `gh pr create`, no `git stash`, no amend / rebase / force push. Stage by path; never commit `.claude/settings*.json`. Author `Jack Tran <107062939+jack9x@users.noreply.github.com>`, no Co-Authored-By / Claude-Session lines. `.pot` (makepot) runs last. If something in this plan does not match the code, stop that CP and report instead of guessing.
