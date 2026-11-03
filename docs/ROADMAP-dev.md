# Dev Roadmap — Workflow Core + v1.0 and beyond

**Owner:** Jack · **Status:** Planning · **Product scope:** [ROADMAP-v1.0.md](ROADMAP-v1.0.md) · **Design:** [workflow-core-design.md](workflow-core-design.md) · **Why:** [workflow-core-decisions.md](workflow-core-decisions.md)

## Goal

One ordered list of backend and UI work, from the v0.1 code to v2.0. [ROADMAP-v1.0.md](ROADMAP-v1.0.md) says *what* each page gets. This roadmap says *how and in which order* it gets built. `design §N` points to a section of [workflow-core-design.md](workflow-core-design.md).

Sizes are proposals until Jack estimates after M1, as ROADMAP-v1.0 says. **S** ≤ 3 days · **M** ≈ 1–2 weeks · **L** ≈ 3–4 weeks.

## Build order

**F0 → F1 (+ identity phases 1–2) → M1 → M2 → F2 → M7 → M8 → M3 → M4 → M5 → M6 → M9** → v1.1 → v1.2 → v2.0 → Public API

Changes from the ROADMAP-v1.0 order:
- **F0 and F1 come first.** M1 needs step outputs, templates and the new step contract. M2 needs stored runs. Building M1/M2 on the v0.1 runner means building them twice.
- **F2 comes after M2.** Tables replace the CPT before v1.0, while there's no merchant data yet: dev data is reset, no migration.
- **M6 stays after M8.** The inactive-customer trigger reuses the pattern of M8's abandon sweep.
- **Identity inside F1** *([plan-identity](plan-identity.md))*: F1 backend first, so the restyle reads step icons and summaries from the new catalog; then the restyle (phase 1, built on `jack/feature/identity-phase-1`: screens, cards and wording; PHP step wording only); then the forms (phase 2, with F1.9–F1.12), built once in the new style. Nothing is built twice.

| # | Phase | Size | Risk | Depends on | Status |
|---|---|---|---|---|---|
| F0 | Move and rename | S | 1 | — | ✅ Done |
| F1 | Workflow Core foundation | L | 3 | F0 | ✅ Done |
| M1 | Customer emails + coupons | L | 3 | F1 | ✅ Done |
| M2 | Wait + run history | L | 3 | F1 | ✅ Done |
| F2 | Workflows table | M | 3 | F1 | ✅ Done |
| M7 | Cart tracking + Settings + Health check | L | 3 | F1 | ✅ Done |
| M8 | Abandoned cart + checkout | L | 2 | M1, M2, M7 | ✅ Done |
| M3 | Review request | M | 2 | M2 | — |
| M4 | Welcome series | S | 2 | M2 | — |
| M5 | High-value order alert | S | 1 | — | — |
| M6 | Win-back | M | 2 | M8 | — |
| M9 | Release hardening | M | 2 | all | — |
| v1.1 | Control flow | L+ | 3 | v1.0 | — |
| v1.2 | Yay ecosystem + Pro connectors | L+ | 3 | v1.1 | — |
| v2.0 | AI | L+ | 3 | v1.2 | — |
| API | Public extension API | M | 2 | v1.2 stable | — |

Status: ✅ done (merged into `develop`, CI green) · ⏳ done, merge pending · In progress · Next · — not started. Mark a phase and its items ✅ when it lands in `develop`.

Risk scale as in ROADMAP-v1.0: 1 = easy to undo · 2 = new logic · 3 = stores data, runs in the background, emails shoppers or creates coupons.

---

## F0 — Move and rename · S (1 day + ½ day baseline)

**Status:** ✅ Done 2026-10-08, PR #11 merged into `develop` with CI green (commits `e225947`, `825a60f`, `998ac44`).

Do it when no feature branch is open: it touches every file, so it's cheapest as one quick PR that everyone rebases on once.

- ✅ **F0.0 Verify baseline** *([plan-infra](plan-infra.md))*, the first checkpoint and commit of the F0 branch, before F0.1:
  - phpcs inside the project (`dev/tools/lintphp`), `pnpm lint:php`, the 12 current errors fixed.
  - Vitest with first tests on `model/` and the template registry; `pnpm test`.
  - Stop hook adds tests; CI on `develop`: lint → lint:php → test → build.
- ✅ **F0.1** Move `includes/Engine/{Workflows,Blocks,Data}` and `Engine/Plugins.php` to `includes/Workflow/`, then run `composer dump-autoload`. *(design §1)*
- ✅ **F0.2** Rename Blocks → Steps: PHP classes, `apps/admin-page/src/routes/workflows/blocks/` → `steps/`, types, `.claude/docs/context-php-backend.md`.
- ✅ **F0.3** No behaviour change. Check: `pnpm build`, `pnpm lint:php`, `pnpm test` (same results as before F0), manual run of both triggers and all 4 actions.

## F1 — Workflow Core foundation · L (~3 weeks)

**Status:** ✅ Done — backend (PR A #13), identity phase 1 (PR B) and F1.9–F1.12 with identity phase 2 (PR C #15, merge `4ec1468`) merged into `develop`. Regression pass done (manual pass + code review fixes, Template Library and list per mock v2).

**Backend**
- ✅ **F1.0 PHPUnit** *([plan-infra](plan-infra.md))* — plain unit tests, no WordPress loaded (`dev/tools/phpunit`); CI step. First tests: template parser, Validator v2, Scope.
- ✅ **F1.1 Storage** *(§2)*
  - `runs`, `run_steps` tables with upgrades.
  - A version's logic never changes (its layout may); runs pin their version.
- ✅ **F1.2 Data layer v2** *(§4)*
  - Types incl. boolean, money, list, entity.
  - Entity types order, customer, site with relations (`order.customer`, `order.items`).
  - `RefResolver` and `Scope`. Port `OrderData`, `CustomerData`, `SiteData`.
- ✅ **F1.3 Templates tier 1** *(§6)*
  - PHP parser, renderer, filter registry (`default`, `date`, `money`, `upper`, `lower`, `join`).
  - Rendering moved from `Data/Variables.php` to `Template` + `Scope`; `Variables` removed in PR C (`Validator::tag_error()` covers merge tags). Old `{{ item.field }}` tags keep working.
- ✅ **F1.4 Step contract** *(§5)*
  - `Step` base, `Field` builder, `outputs()`, `StepResult`, summary templates, `icon()` name *(L5)*.
  - Plugin logo: one one-colour SVG file per plugin, drawn as a CSS mask *(L5)*.
  - Parity test: the PHP catalog and what TS expects can't drift *([plan-infra](plan-infra.md))*.
  - Catalog in `yayMatikMeta`; update `apps/admin-page/src/types/localize.d.ts`.
- ✅ **F1.5 Executor** *(§3)*
  - Dispatcher replaces `Runner` collect / flush. Worker: Action Scheduler hook + row lock.
  - Cursor walk, slices, per-step error policy (stop / continue). **Stop is the default** (design §3, confirmed 2026-10-08); a step type may declare `continue` in PHP. No UI toggle in v1.0. A skipped step (gone entity, unsubscribed) is not a failure.
  - Causation (`parent_run_id`, `root_run_id`, `depth`) replaces the in-memory chain.
- ✅ **F1.6 Validator v2** *(§4, §6)*
  - Available variables per step; type checks; template checks.
  - Same issue codes as today (`no_trigger`, `unlinked`, `invalid_config`, `missing_data`, …).
  - An issue on a step names the field it's about (`field`), so the editor can say "Add order note needs a note" *([plan-identity](plan-identity.md))*.
  - Validator and REST messages use the plan-identity wording (Activate / Deactivate, Update).
- ✅ **F1.7 Modules** *(§8)* — one module per plugin lists its steps and data items; the registry reads the modules. Free vs Pro is the folder (`includes/Pro/`).
- ✅ **F1.8 Runs REST** — list and detail endpoints (the UI comes in M2). Workflow responses also return `activatedAt`, for "Active since …" on the overview *([plan-identity](plan-identity.md))*.

**UI**
- ✅ **F1.9 Step forms** *(§5)*
  - Generic `StepForm` with widgets: text, textarea, emails, select, multiselect, toggle, number, notice.
  - Read-only lines (e.g. "From" in Send internal email) use `notice`, not a new widget *([plan-identity](plan-identity.md))*.
  - valibot rules built from the catalog.
  - `defineStep( { id, Form } )` for core.
- ✅ **F1.10 Port the 7 steps**
  - The 6 built steps' simple forms move to the generic form; their `schema.ts` / `Form.tsx` are deleted. The 3 coming-soon steps (Send email, Generate coupon, Wait) keep their custom forms on the shared widgets until M1 / M2.
  - Condition keeps its custom form, using the `rules` field type, written as a sentence *([plan-identity](plan-identity.md))*.
  - "Change trigger" / "Change action" in the panel header swaps the step: same node and lines, settings reset (asks first when they were edited).
- ✅ **F1.11 Template checks in TS** *(§6)* — parser only: errors and paths, same messages as PHP; errors show under variable fields.
- ✅ **F1.12 "Insert variable"** — lists the step's available variables, incl. step outputs and dotted paths.

**Done when:** both triggers and all 4 actions behave as today when they succeed, and a failing step stops the run (design §3); every run and step is stored; `pnpm build`, `pnpm lint:php`, `pnpm test` and PHPUnit pass in CI.

## M1 — Customer emails + coupons · L · risk 3

**Status:** ✅ Done. Backend (PR #19, A1–A5) and UI + templates (PR B) merged into `develop`.

**Backend**
- ✅ **M1.1 Send email** — WooCommerce email look; To defaults to the customer; subject, heading, message; unsubscribe link added automatically.
- ✅ **M1.2 Generate coupon** — outputs `code`, `amount`, `expires_at`; limited to the customer's email; single use.
- ✅ **M1.3 Unsubscribe** — storage, token link, confirmation page; unsubscribed customers are skipped, with the reason in run history. The page is self-contained, not wrapped in the store theme, so it works on block and classic themes.
- ✅ **M1.4 Yes/no fields** — `customer.is_first_order`, `customer.unsubscribed`.
- ✅ **M1.5 Preview + Send test REST** — render with a sample scope: a recent order, or made-up data labelled "Sample data".
- ✅ **M1.6 Email block: coupon box.** Inserted from the "Blocks" group of Insert variable (UI in B2).

**UI**
- ✅ **M1.7 Send email panel** — generic form with a rich editor for the message (WordPress classic editor, Visual / Code), "Insert variable" incl. Blocks, Preview dialog, Send test, unsubscribe note. Not a custom form: `defineStep` `Footer` / `Actions`.
- ✅ **M1.8 Generate coupon** — generic form; card reads e.g. "10% off, expires in 30 days". Free shipping with no shipping method that takes a coupon (or only ones that also need a minimum) shows a notice in the panel; it never blocks Activate.
- ✅ **M1.9 Condition** — Is true / Is false for yes/no fields, no value needed.
- ✅ **M1.10 Warning before Activate** — a step uses a coupon tag with no Generate coupon above it (or that step was removed): a step issue on the tag, which blocks Activate. Rules only PHP checks (e.g. a percentage over 100) show under their field after a save and block Activate too. The free shipping notice (M1.8) is not an issue: a store without a coupon free shipping method can still activate.
- ✅ **M1.11 Template Library** — category filters (already in `develop`); "Most popular" in a fixed order until installs are tracked.
- ✅ **M1.12 Template preview** — Other versions and Steps; "Before you start" when the free shipping coupon needs a shipping method.

**Templates:** ✅ First-order thank-you ×2 (10% off; free shipping), on `customer.is_first_order` with the coupon box.

**Moved or open**
- Cross-sell / upsell templates: coupon paths updated; unlocked in M2 PR A.
- Unsubscribe page text in Settings → M7.6; privacy exporter / eraser for unsubscribes → M7.4; product grid → M2.5.
- Not planned yet: admin list of unsubscribes, multiple To addresses, dark mode inside the editor's iframe (stays light).

**Follow-ups**
- Flaky `pnpm build` while `pnpm dev` runs (`vite-plugin-external` stand-ins): upgrade or replace the plugin.
- Preview / Send test show to anyone who can open the editor, the REST routes need `manage_woocommerce`. Fine while the page needs `manage_options`; revisit if that changes.
- Editor-level test for server issues (merged after autosave, "Needs setup", passed to the panel): in the e2e plan.

## M2 — Wait + run history · L · risk 3

**Status:** ✅ Done. Backend (PR #26, `22bf12a`) and UI (PR #27, `acb2acc`, M2.6–M2.10) merged into `develop`.

**Backend**
- ✅ **M2.1 Wait step** — number + unit (minutes, hours, days, weeks), a whole number up to a year; the run moves to waiting and resumes through Action Scheduler.
- ✅ **M2.2 Cancel on Deactivate / Update** *(§3)* — cancel waiting runs, return the count; the rate-limit pause cancels them too, and a run stops before its next step once its workflow is off.
- ✅ **M2.3 Runs REST** — status filter, paging, total, subject label and link, steps run; run detail names each step type.
- ✅ **M2.4 List stats** — runs in the last 30 days, last run, waiting runs, on every workflow response.
- ✅ **M2.5 Email block: product grid** — cross-sell / upsell sources; `order.has_cross_sells` / `has_upsells`, `customer.paid_order_count`.

**UI**
- ✅ **M2.6 Wait** — form and card ("Wait 1 hour", clock icon).
- ✅ **M2.7 Overview: runs table** — started, subject, status, steps run; loading and empty states.
- ✅ **M2.8 Run detail** — each step with result and time; links to the order (customer links with M4).
- ✅ **M2.9 Deactivate / Update dialog** — says how many waiting runs will be cancelled.
- ✅ **M2.10 Workflow list** — Runs and Last run columns, status filter.

**Templates:** ✅ Cross-sell / upsell ×4, installable since PR A, checked end to end in PR B (wait, email with product grid, cancel on Deactivate).

**Follow-ups**
- Kit Pagination (`components/ui/pagination.tsx`) has hard-coded English ("Page X of Y", "Go to") → M9.2.
- Preview picks the newest paid order even with no cross-sells / upsells, so the grid is left out with no hint; option: a note in the Preview dialog.
- Product grid: the square's grey and the price colour are fixed, not the store's email colours; Outlook desktop ignores max-width (the square fills the column).
- A run that ends at a Condition's No path shows Completed; consider a short outcome in the runs table (e.g. "Stopped at Condition (No)").
- Insert variable shows the customer twice (the Customer group and the Customer section inside Order); from F1.12.

## F2 — Workflows table · M (3–5 days)

**Status:** ✅ Done. Merged into `develop` (PR #29, `6425140`).

- ✅ **F2.1** `workflows` + `workflow_versions` tables; repository rewritten onto them. *(§2)*
- ✅ **F2.2** Remove the CPT; dev data is reset (`./run.sh reset-data`), no migration.
- ✅ **F2.3** Check: list, editor, versions and runs work on new workflows.
- ✅ **F2.4** Split graph layout (positions) from logic in stored graphs. *(§7)*

## M7 — Cart tracking + Settings + Health check · L · risk 3

**Status:** ✅ Done. Merged into `develop` (PR #30, PR #31, `cfb9337`).

**Backend**
- ✅ **M7.1 Cart tracking** — carts table; logged-in customers and guests; email captured at checkout (classic + block).
- ✅ **M7.2 Restore link** — refills the cart and opens checkout.
- ✅ **M7.3 Settings** — storage and REST.
- ✅ **M7.4 Retention and privacy** — cleanup of carts and runs, including runs of deleted workflows; personal data exporter and eraser.
- ✅ **M7.5 Health check v1** *(§9)*.
  - (a) Runs stuck in `queued` or `waiting` with no Action Scheduler job → queue again.
  - (b) Runs left `running` with no run or recovery job → recover.
  - (c) Jobs late over 15 min → server cron guide (info).
  - (d) Cleanup job missing or late → schedule again.

**UI**
- ✅ **M7.6 Settings page** — Cart tracking, Emails, Run history.
- ✅ **M7.7 Health check panel** — each problem with its proposed fix.
- ✅ **M7.8 Store front** — message when a restore link's cart is gone.

**Follow-ups**
- Leave dialog titles differ: Settings "Unsaved changes", editor "Leave without saving?".
- Unused dependencies in `apps/admin-page`: zod, react-hook-form, @hookform/resolvers, @formisch/react.
- `routes/settings/api/request.ts` repeats the private `request()` of `workflow.api.ts`.
- Settings changed in another tab show only after a reload (no refetch); the health check is reachable only when Settings load.

## M8 — Abandoned cart + checkout · L · risk 2

**Status:** ✅ Done. Merged into `develop` (PR #32, PR #33, `374260a`).

**Backend**
- ✅ **M8.1 Abandon sweep (no claims)** *(§3)* — a 5-minute job, 200 carts each; a cart's first abandon in its cycle is the claim. (#32)
- ✅ **M8.2 Cart entity type** *(§4)*. (#32)
- ✅ **M8.3 Triggers** — Cart abandoned, Checkout abandoned; a cart never starts both. (#32)
- ✅ **M8.4 Exit condition** — stop the run when an order is placed for the cart or customer, or the cart is gone (no setting; see design §3 "Stop on order"). (#32)
- ✅ **M8.5 Email blocks** — cart items, "Restore my cart" button. (#32)

**UI**
- ✅ **M8.6 Trigger panels** — notes, plus a warning when cart tracking is off.
- ✅ **M8.7 Fixed note in the trigger panel, no setting:** the run always stops when an order is placed.
- ✅ **M8.8 Templates UI** — "Needs cart tracking" badge, "Before you start" in the preview.

✅ **Templates:** Abandoned cart ×3, Abandoned checkout ×2.

**Follow-ups**
- A trigger's `context()` and `refs()` load the order 2–3 times per event; load it once.
- Cart tracking off blocks Activate for workflows with a cart trigger (M1.10 pattern), and Update when it changes the trigger to one.
- `woocommerce_email_base_color` lookup repeated in GenerateCoupon, UnsubscribePage, CartItems; one helper after v1.0.
- A shopper who comes back and leaves again: the series goes on; only an order, a gone cart or an unsubscribe stops it.
- Emails show the price, line total, total and currency the shopper saw; live name, image and link; a gone product shows its snapshot name without a link.
- Template preview 'Not installed' badge and install note use the kit's pale warning variant; move to WarningBadge / warning-soft tokens after v1.0.

## M3 — Review request · M · risk 2

- **M3.1 BE** — `products_not_reviewed` data and the "Leave a review" email block.
- **M3.2 UI** — "Before you start": turn on product reviews.
- **Templates:** Review request ×2.

## M4 — Welcome series · S · risk 2

- **M4.1 BE** — Customer account created trigger (customer entity from `WC_Customer`).
- **M4.2 UI** — Customers section in the trigger picker; welcome email block.
- **Templates:** Welcome ×2.

## M5 — High-value order alert · S · risk 1

- **M5.1** — Move the template to "order starts processing": Order status changed, from Pending, On hold or Failed to Processing; condition order total ≥ 500. Known limit: processing → on-hold → processing alerts again, as Order created's once-per-order claim is lost.
- **Templates:** High-value order alert ×1.

## M6 — Win-back · M · risk 2

- **M6.1 BE** — Customer inactive query trigger, checked daily in batches (the pattern of M8.1), plus the `trigger_claims` table (workflow_id, subject, claimed_at; unique per workflow and subject) and its store.
- **M6.2 UI** — trigger panel: days (default 90), "Checked once a day".
- **Templates:** Win-back ×2.

## M9 — Release hardening · M

- **M9.1** End-to-end test of all 18 templates; classic and block checkout; light and dark admin. Run `./run.sh check-carts` on a MySQL 8 site (erase `REGEXP` tested only on MariaDB 11.2).
- **M9.2** Translations and release build.
- **M9.3** Leave unfinished steps unregistered for the release; check plugin logos load (CSS mask) when assets come from a CDN. *(§8)*
- **M9.4** Release checklist items picked in ROADMAP-v1.0.

**Follow-ups**
- Delete `dev/scripts/reset-data.sh` once every dev site has run it (F2).

---

## v1.1 — Control flow · L+

- **V11.1 For each** — body / done handles, saved cursor per item, limits; container card in the editor. *(§3, §7)*
- **V11.2 Get data** — Get orders / customers / products / coupons with filters, returning lists.
- **V11.3 Interval trigger** — every N minutes / hours / days, at a time, on weekdays; reuses M6.1; schedule UI.
- **V11.4 Call workflow** — action + "Called by workflow" trigger with typed inputs; depth limit. *(§3)*
- **V11.5 Manual runs** — Manual trigger, Run now, Test run on a picked order; dry run via `simulate()`.
- **V11.6 More control steps** — Switch, Set variable, Stop, A/B split.
- **V11.7 Retries** — backoff; replay a failed run from the failed step (button in run detail).
- **V11.8 Product items** — the v1.1 list in ROADMAP-v1.0: Branched post-purchase, Nth order reward, alerts, order tags.
- **Backlog** — Opt-in mode (consent checkbox) + transactional-only workflows. "Run once per order" through M6's `trigger_claims`: today a status trigger starts again when an order returns to the status (duplicate email / coupon, a second high-value alert).

## v1.2 — Yay ecosystem + Pro connectors · L+

- **V12.1 Pro modules** — YayMatik Pro registers its modules and multi-plugin templates. *(§8)*
- **V12.2 Yay plugins** — register their steps (through their module) and templates; custom forms wait for P4. Handle steps from an inactive plugin softly; today they fail as "Unknown step type." *(§5, §8)*
- **V12.3 Feature directory** — "Install X to use this" in the pickers and the template library. *(§8)*
- **V12.4 Connector basics** — credentials store, connector settings UI.
- **V12.5 Connectors** — webhook in, HTTP request, Slack, Google Sheets, SMS; CSV source and export.
- **V12.6 Templates tier 2** — "Show if" on email blocks. *(§6)*

## v2.0 — AI · L+

- **V20.1** Choose the provider *(§10)*; turn the catalog into JSON Schema for structured output.
- **V20.2** Generate a draft workflow from a prompt, a business profile or Woo reports, using the feature directory. Always saved as a draft.
- **V20.3** Review / lint a workflow; explain a failed run from `run_steps`.
- **V20.4** AI steps: AI condition (smart judgement), generate text, extract / classify. Each has a budget, timeout and fallback branch.
- **V20.5 UI** — AI box in the Create workflow dialog (replaces "Coming soon"), review panel, "Explain" on a run.

## Public extension API · M

After the Pro connectors and Yay integrations are stable. *(§10)*

- Freeze the registry and the step contract as v1; plugin steps get custom forms.
- Developer docs and an example add-on.

---

## Risks

| Risk | Impact | Mitigation |
|---|---|---|
| F0 + F1 push M1 back ~3 weeks | First visible v1.0 feature comes later | M1 and M2 get shorter on the new base; nothing is built twice |
| F0 rename while a branch is open | Painful merge conflicts | Do F0 when no branch is open; merge it the same day |
| WP-Cron off or late, large stores | Waits run late, slow sites | Health check (M7.5), batches, limits, rate limit |
| Personal data in run history | Privacy complaints | Retention setting, exporter / eraser, small snapshots |
| Internal API changes break Yay plugins | Broken integrations | One owner for the registry; public API only after it's stable |
| One developer for everything | Dates slip | Estimates after M1; cut candidates as in ROADMAP-v1.0 |
