# Roadmap: Workflow page UI — v1.0

## Goal

Ship v1.0 with 8 workflows as 18 install-ready templates (source: `~/Desktop/temp/yayautomation-workflow-plan-v1.md`), plus every page, block and engine piece they need to run end to end.

## Decisions (agreed 2026-10-07)

- **Scope:** UI + engine. A template is done only when it installs, turns on and runs.
- **Conditions = data fields.** Keep the one generic Condition block. The plan's 11 conditions become fields in the data registry (PHP `Engine/Data`), so they show up in "Add a variable", criteria and merge tags with no new condition block.
- **Presets = separate templates.** Each preset is its own card in the library. Presets of one workflow share a `family` and link to each other in the preview.
- **Docs:** this file is the technical plan, private (gitignored). The leader roadmap is [`docs/ROADMAP-v1.0.md`](../../../docs/ROADMAP-v1.0.md) on branch `jack/feature/workflow-page-ui-roadmap`: no table / field / REST names there.
- **Proposed defaults** are marked *(proposed default, pending confirmation)* and listed again under Open questions. Build with the default until confirmed.

## Risk scale

| Risk | Meaning |
|---|---|
| 1 | UI-only, refactor or template data; easy to undo |
| 2 | New feature logic, block, form, validation or REST endpoint |
| 3 | New tables / migrations, background jobs, emails to shoppers, money (coupons), privacy data; hard to undo |

## Where we are (develop @ 3d435aa)

| Area | Exists |
|---|---|
| List page | Table (name, status, trigger, last updated), bulk actions, empty state, Create workflow dialog, Template Library modal (search + sort) |
| Template preview | Read-only canvas, "About this template" (description, plugins used), Install → inactive draft → editor |
| Editor | Left step panel, trigger/action pickers grouped by plugin → category, generic Condition (criteria, AND/OR, variable as value), Add a variable, review/error modes, autosave draft, Turn on / Apply |
| Overview | Live canvas, unapplied changes, Version history tab, Workflow runs **empty state only** |
| Settings | Empty page |
| Triggers | Order created, Order status changed |
| Actions | Send internal email, Change order status, Add order note, Update order custom field |
| Data | `order`, `customer` (from the order's billing), `site`; types number / string / enum / date |
| Engine | Triggers `listen()` → Runner queues one Action Scheduler job per workflow → walks the graph **in one go**. No pause, no run records (only `Logger::error`) |
| Templates | 1: High-value order alert |

**Gaps that block v1:** no way to wait (runs are one-shot), no run records, no customer-facing email, no unsubscribe, no coupon, no cart tracking, no customer/cart triggers, no boolean fields, actions cannot hand data to later steps.

## v1.0 templates (18)

Presets are proposals — confirm names and timings before building each milestone.

| # | Family | Template (preset) | Trigger | Steps |
|---|---|---|---|---|
| 1a | Abandoned checkout | Checkout reminder | Checkout abandoned | Condition (cart total > 0, not unsubscribed) → Wait 1 h → Condition (no order since trigger) → Send email |
| 1b | Abandoned checkout | Checkout reminder + coupon | Checkout abandoned | … → Email 1 (1 h) → Wait 23 h → Condition (no order) → Generate coupon → Email 2 |
| 2a | Abandoned cart | Cart reminder | Cart abandoned | Condition → Wait 1 h → Condition (no order) → Send email |
| 2b | Abandoned cart | 3-email series | Cart abandoned | Email at 1 h, 24 h, 72 h, each behind "no order since trigger" |
| 2c | Abandoned cart | 3-email series + coupon | Cart abandoned | As 2b; Generate coupon before the 3rd email |
| 3a | First-order thank-you | Thank you + % off next order | Order status → Completed | Condition (paid order count = 1, not unsubscribed) → Generate coupon (10 %) → Send email |
| 3b | First-order thank-you | Thank you + free shipping | Order status → Completed | Same, coupon = free shipping |
| 4a | Cross-sell / upsell | Cross-sell, first-time buyers | Order status → Completed | Condition (count = 1, has recommended products, not unsubscribed) → Wait 7 d → Condition (status not refunded/cancelled) → Send email |
| 4b | Cross-sell / upsell | Cross-sell, repeat buyers | Order status → Completed | Same with count > 1 |
| 4c | Cross-sell / upsell | Cross-sell + coupon | Order status → Completed | Wait 14 d → … → Generate coupon → Send email |
| 4d | Cross-sell / upsell | Upsell after 30 days | Order status → Completed | Wait 30 d → … → Generate coupon → Send email (upsells) |
| 5a | Review request | Review request | Order status → Completed | Condition (reviews enabled, not unsubscribed) → Wait 7 d → Condition (not refunded, has unreviewed products) → Send email |
| 5b | Review request | Request + reminder | Order status → Completed | 5a + Wait 7 d → Condition (still unreviewed) → Send email |
| 6a | Welcome series | Welcome email | Customer account created | Condition (role = customer, not unsubscribed) → Send email |
| 6b | Welcome series | 3-email welcome series | Customer account created | Email day 0, day 3, day 7; optional "paid order count = 0" before 2 and 3 |
| 7a | Win-back 90 days | Win-back + discount | Customer inactive (90 d) | Condition (not already sent, not unsubscribed) → Generate coupon (15 %) → Send email |
| 7b | Win-back 90 days | Win-back + free shipping | Customer inactive (90 d) | Same, coupon = free shipping |
| 9 | High-value order alert | High-value order alert | Order status → Processing | Condition (order total > 500) → Send internal email |

#9: change the existing template from Order created to Order status changed → Processing (per the plan). Slack + order tag move to v1.1.

Not in v1.0: #8 Branched post-purchase → v1.1 (with #11, #12, #13); #10, #14 → v1.2. Numbering follows the source plan, so #8 is skipped above.

Preset names, timings and coupon values are *(proposed default, pending confirmation)*. Per-template email timing and coupon type / value / expiry live in the roadmap's **Template details (proposals)** table: that table is the source, keep this one in sync.

## Building blocks

### Triggers (3 new + 2 existing)

| Trigger | Plugin / group | Config | Provides | Fires |
|---|---|---|---|---|
| Order status changed | WooCommerce | exists | order, customer | exists |
| Checkout abandoned | WooCommerce · Carts | none (timeout in Settings) | cart, customer | Cart scan marks a cart abandoned that reached checkout with an email |
| Cart abandoned | WooCommerce · Carts | none | cart, customer | Cart scan marks a cart abandoned that never reached checkout (customer known: logged in, or email known from an earlier visit) |
| Customer account created | WooCommerce · Customers | none | customer | `woocommerce_created_customer` + `user_register` (role customer), once per user |
| Customer inactive | WooCommerce · Customers | `days` (default 90) | customer | Daily scan: customers whose last paid order is `days` old (one-day window, claimed once per last order) |

Order created stays (not used by v1 templates).

**One cart, one trigger:** a cart that reached checkout fires **only** Checkout abandoned, never Cart abandoned. Decided when the scan marks it abandoned (`reached_checkout`), so a shopper never gets both series. The rule is shown in both trigger panels (UI §5).

### Conditions → data fields

New field type **`boolean`** (operators `is_true`, `is_false`; no value input). PHP `Operators` + TS `operatorLabels`/`needsValue`.

| Plan condition | Field | Type | Notes |
|---|---|---|---|
| Cart total > 0 | `cart.total` | number | |
| Customer not unsubscribed | `customer.unsubscribed` | boolean | Unsubscribe list, by email |
| No order placed since trigger | `run.customer_ordered` | boolean | Paid order by the same user/email after the run started |
| Customer paid order count | `customer.paid_order_count` | number | Paid statuses, by user id, else billing email (guests) |
| Order status is not … | `order.status` | enum | Exists. Two criteria with AND for "not refunded / cancelled" |
| Order has recommended products | `order.has_recommendations` | boolean | Cross-sells/upsells of the order's products, minus what was bought |
| Product reviews enabled | `site.reviews_enabled` | boolean | `woocommerce_enable_reviews` |
| Products not yet reviewed | `order.has_unreviewed_products` | boolean | Customer has no approved review on at least one item |
| User role | `customer.role` | enum | WP roles |
| Not already sent | `run.already_sent` | boolean | A finished run of this workflow for this customer after their last paid order |
| Order total | `order.total` | number | Exists |

New **data items**: `cart` (total, item_count, items, restore_url, abandoned_date), `coupon` (code, amount, expiry_date, description), `run` (always available: started_date, customer_ordered, already_sent).

New **display-only variables** (merge tags, `conditional: false`): `{{ cart.items }}`, `{{ cart.restore_url }}`, `{{ order.recommended_products }}` (product grid), `{{ order.review_links }}`, `{{ customer.unsubscribe_url }}`, `{{ customer.account_url }}`.

`customer` today reads the order's billing. Customer triggers pass a `WC_Customer`; cart triggers pass a cart adapter with the same billing getters. Billing email falls back to the user email.

### Actions (3 new + existing)

| Action | Plugin / category | Config | Notes |
|---|---|---|---|
| Send email | WooCommerce · Email | to (default `{{ customer.email }}`, editable *(proposed default, pending confirmation)*), subject, heading, message | WooCommerce template; adds unsubscribe footer; skipped (logged) when the recipient is unsubscribed |
| Wait | YayAutomation · Flow | amount + unit (minutes / hours / days; minutes *(proposed default, pending confirmation)*) | Pauses the run; resumes via Action Scheduler |
| Generate coupon | WooCommerce · Coupons | discount type (% / fixed cart / free shipping), amount, expires after N days, minimum spend, individual use, limit to customer email (default on), code prefix | **Provides `coupon`** to steps below it |
| Send internal email | exists | | = plan's "Send admin email" |

New plugin group **YayAutomation** (always active) for Wait; `Plugins.php` + `PluginIcon`.

**Action-provided data:** an action may declare `provides` (Generate coupon → `coupon`). Data available at a step = trigger `provides` + `provides` of every action on every path above it + `run`/`site`. Changes `availableData()` (TS `model/validation.ts`) and `WorkflowValidator` (PHP): `missing_data` on a Send email that uses `{{ coupon.code }}` without a coupon above it. "Add a variable" lists only what is available at that step.

## Engine pieces

1. **Runs** — table `{prefix}yay_automation_runs`: `id, workflow_id, version_id, trigger, subject, customer_email, status (running | waiting | completed | failed | cancelled), current_node, resume_at, vars JSON, created_at, updated_at`. Table `{prefix}yay_automation_run_steps`: `run_id, node_id, block, result (done | skipped | failed | true | false | waiting), message, created_at`. Created with `dbDelta` behind `yay_automation_db_version` (same as versions). Runner writes a run per job and a row per step. Retention: Settings (default 90 days), daily cleanup.
2. **Wait / resume** — Wait stores `current_node`, `resume_at`, `vars` (e.g. coupon id) and schedules `yay_automation_resume_run` (single action at `resume_at`, args = run id). On resume: workflow still active and node still in the live graph → rebuild context from trigger args + `vars`, continue from the node's children; otherwise cancel the run (safety net only, see 3–4). Conditions after a Wait read fresh data (resolvers run at evaluate time).
3. **Turn off** — cancels waiting runs **immediately**, in the same request: status `cancelled` and `as_unschedule_action()` for each resume job. A run never shows "Waiting until…" on a workflow that is off. Turn off dialog states how many waiting runs will be cancelled. Turning back on does not revive them.
4. **Apply changes** — waiting runs whose node id still exists in the new live graph keep waiting; the rest are cancelled immediately, same as Turn off. Apply dialog states how many will be cancelled.
5. **Delete workflow** — cancels its waiting runs (unschedule) before deleting.
6. **Unsubscribe** — table `{prefix}yay_automation_unsubscribes (email, created_at)`; signed link `?yay-automation-unsubscribe=<token>` → small front-end confirmation page; WP privacy exporter/eraser.
7. **Cart tracking** — table `{prefix}yay_automation_carts`: `id, user_id, session_key, email, contents JSON, total, currency, reached_checkout, status (active | abandoned | recovered | ordered), token, last_activity, abandoned_at`. Hooks: cart updates (classic + Store API), checkout email capture (classic field blur + block checkout via Store API extension), order placed → `ordered` / `recovered` (if from a restore link). Recurring scan (15 min) marks carts abandoned after the timeout and emits the trigger. Restore link refills the cart and goes to checkout. Privacy exporter/eraser.
8. **Daily scan** for Customer inactive: batched (Action Scheduler), one event per customer, claimed by last order id.

## UI roadmap by page

### 1. Workflow list `#/workflows`

- New columns: **Runs (30 d)** and **Last run** (relative time). Data from the runs table, returned with the list. *(proposed default, pending confirmation: in v1.0, built in M2)*
- Status filter tabs: All · Active · Inactive · Draft (client-side). *(proposed default, pending confirmation)*
- Empty state unchanged.

### 2. Create workflow dialog

- No change. Trigger picker shows the new triggers (Carts, Customers sections under WooCommerce).

### 3. Template library (`?templates=list`)

- 18 cards. Category chips above the grid: All · Recover carts · Post-purchase · Customer lifecycle · Alerts. Chip state local, like search.
- Card: name, description (3 lines), category, plugin icons, plus a **"Needs cart tracking"** badge for families 1–2 when tracking is off.
- `WorkflowTemplate` gains `family`, `presetLabel`, `requirements: ('cart_tracking' | 'reviews')[]`. `popularity` set from the plan's demand order (static until real install counts) *(proposed default, pending confirmation)*.
- Sort and search unchanged; search also matches family.

### 4. Template preview `#/workflows/templates/:id`

- About panel adds:
  - **Other versions** — sibling presets (same `family`) as links.
  - **Before you start** — unmet requirements with a fix link (Settings → Cart tracking; WooCommerce → Products → Reviews).
  - **Steps** — one line per step (block label + summary), same text as the cards.
- Install stays: draft → editor. Toast says "Review the emails, then turn it on".

### 5. Editor `#/workflows/:id/edit`

- **Pickers:** WooCommerce gets sections Carts / Customers (triggers) and Coupons (actions); new YayAutomation group with Wait.
- **Trigger panels:**
  - Checkout / Cart abandoned: "No configuration" + note "Carts count as abandoned after N minutes of inactivity (Settings → Cart tracking)". Warning alert with a link when tracking is off; Turn on is blocked by a new issue `requirement_missing`.
  - One-cart-one-trigger note, in both panels. Cart abandoned: "Carts that reached checkout start Checkout abandoned instead, never this trigger." Checkout abandoned: "Only carts that reached checkout with an email. They never start Cart abandoned."
  - Customer account created: no configuration.
  - Customer inactive: Days (number, min 7, default 90) + note "Checked once a day".
- **Wait** card summary "Wait 1 hour"; form = number + unit select. Card shows a clock icon.
- **Generate coupon** form: discount type (segmented), amount (hidden for free shipping), expires after N days, minimum spend, individual use, limit to customer email, code prefix. Summary "10 % off, expires in 7 days". Hint "Use {{ coupon.code }} in a later email".
- **Send email** form: To (default `{{ customer.email }}`, Add variable), Subject, Heading, Message (textarea + Add variable). Footer note "An unsubscribe link is added automatically". Buttons **Preview** (dialog with the WooCommerce-wrapped email, sample data from the last matching order/cart) and **Send test** (to the current admin). New REST: `POST /email/preview`, `POST /email/test`.
- **Add a variable:** lists only data available at the step (incl. Coupon below a Generate coupon, Workflow run always). Display-only variables hidden in criteria (existing `conditional` flag).
- **Condition:** boolean fields show the operator only ("Is true / Is false"), card "Customer unsubscribed is false". Recommend renaming labels so cards read naturally, e.g. "Customer is unsubscribed".
- **Issues:** `missing_data` for coupon variables without a coupon above; `requirement_missing` for cart triggers with tracking off.

### 6. Workflow overview `#/workflows/:id`

- **Workflow runs** section becomes a real table: Started · Subject (Order #123 / cart of email / customer email) · Status badge (Running, Waiting until <time>, Completed, Failed, Cancelled) · Steps run. Prev/next pagination like Version history. Status filter.
- Row click → **Run detail** sheet: timeline of steps (icon, label, result, message, time), e.g. "Condition → False", "Wait → until Oct 9, 3:00 PM", "Send email → sent to a@b.com", "Generate coupon → YAY-7K2P". Link to the order/user.
- Turn off / Apply dialogs say how many waiting runs will be cancelled (engine 3–4). Cancelled runs show "Cancelled" right away, never "Waiting until…".
- New REST: `GET /workflows/:id/runs?page&status`, `GET /runs/:id`.

### 7. Settings `#/settings`

Sections (one REST `GET/PUT /settings`, option `yay_automation_settings`):

- **Cart tracking:** enable (default off *(proposed default, pending confirmation)*), abandoned after N minutes (default 60), capture guest email at checkout, delete carts after N days (default 60).
- **Emails:** unsubscribe page text; link to WooCommerce email settings (sender, colors).
- **Run logs:** keep N days (default 90).

### 8. Front-end (shopper)

- Cart restore link → cart refilled → checkout.
- Unsubscribe confirmation page (theme-wrapped, minimal).

## Milestones

One developer (Jack) builds v1.0, in the order under Build order below. Milestones are listed by number; each milestone is its own branch off `develop` and its own `/wplan` (009+), with the checkpoints below as the starting point.

**M1 — Foundations + First-order thank-you (#3)** · `jack/feature/v1-first-order`
1. Engine: boolean type, `customer.paid_order_count`, `customer.unsubscribed`, `customer.role`, unsubscribe table + link + page, action `provides`, availability per step (PHP + TS). Risk 3.
2. Blocks: Send email (+ preview / test endpoints), Generate coupon. Risk 2.
3. UI: Send email + Generate coupon forms, Add a variable per step, boolean criteria, template metadata (`family`, `presetLabel`, `requirements`), library chips, preview Other versions / Steps. Templates 3a, 3b. Risk 2.

**M2 — Runs + Wait + Cross-sell (#4)** · `jack/feature/v1-cross-sell`
1. Engine: runs + run_steps tables, Runner writes runs, Wait + resume, cancel rules, retention cleanup. Risk 3.
2. UI: Wait block, overview runs table + run detail sheet, list columns Runs / Last run *(pending confirmation)*, Turn off / Apply wording. Risk 2.
3. Data: `order.has_recommendations`, `{{ order.recommended_products }}`. Templates 4a–4d. Risk 2.

**M3 — Review request (#5)** · `jack/feature/v1-review-request`
- `site.reviews_enabled`, `order.has_unreviewed_products`, `{{ order.review_links }}`, `reviews` requirement in preview. Templates 5a, 5b. Risk 2.

**M4 — Welcome series (#6)** · `jack/feature/v1-welcome-series`
- Trigger Customer account created (WC_Customer context, email fallback), `{{ customer.account_url }}`. Templates 6a, 6b. Risk 2.

**M5 — High-value order alert (#9)** · `jack/feature/v1-high-value-alert`
- Move the template to Order status changed → Processing. Template 9. Risk 1.

**M6 — Win-back (#7)** · `jack/feature/v1-win-back`
- Trigger Customer inactive + batched daily scan, `run.already_sent`. Templates 7a, 7b. Risk 2.

**M7 — Cart tracking module** · `jack/feature/v1-cart-tracking`
1. Engine: carts table, hooks (classic + block cart/checkout), email capture, scan, restore link, ordered/recovered, privacy exporter/eraser. Risk 3.
2. Settings page: Cart tracking, Emails, Run logs sections + REST. Risk 2.

**M8 — Abandoned cart (#2) + Abandoned checkout (#1)** · `jack/feature/v1-abandoned-cart` (after M1, M2, M7)
- Triggers Cart abandoned / Checkout abandoned, `cart` item + variables, `run.customer_ordered`, `requirement_missing` issue + panel warning + library badge. Templates 2a–2c, 1a, 1b. Risk 2.

### Release

**M9 — v1.0 hardening** · `jack/release/v1.0`
- Full `/wcheck` pass per template (install → turn on → trigger → wait → email received), light + dark, i18n strings, phpcs, `pnpm build`, release zip (`/wdist`).

**M9 candidates — not in scope until you pick them.** What the code shows today:

| Item | State in code |
|---|---|
| License + auto-updates | `YayAutomationProLicenseAdapter` not registered; `// TODO: EDD setup` in `YayAutomation.php` |
| First-run onboarding | None. First visit lands on the empty list page |
| User docs | Docs links are placeholders (`https://docs.yaycommerce.com/`, `yay_automation_workflow_runs_docs_url`) |
| Translation file | No `languages/` folder, no `.pot`; textdomain loading exists |
| `readme.txt` | Missing |
| Uninstall cleanup | No `uninstall.php`; v1 adds tables (versions, runs, carts, unsubscribes) and Action Scheduler jobs |
| WooCommerce dependency header | No `Requires Plugins: woocommerce` / `WC requires at least` |
| Version bump | `0.1.0` in `yay-automation.php` |

HPOS compatibility is already declared.

### Technical dependencies

What each milestone needs before it can be built (not the order we build in):

| Milestone | Needs | Why |
|---|---|---|
| M1 | — | Builds on what is in develop |
| M2 | M1 | Cross-sell templates send emails and coupons |
| M3 | M2 | Wait (M1 comes with M2) |
| M4 | M2 | 6b uses Wait |
| M5 | — | Existing trigger, condition and Send internal email |
| M6 | M2 | `run.already_sent` reads run records |
| M7 | — | Standalone module + Settings page |
| M8 | M1, M2, M7 | Emails + coupons, Wait, carts |
| M9 | all chosen | |

```
M1 ── M2 ─┬─ M3
          ├─ M4
          ├─ M6
          └─────────┐
M1 ─────────────────┼─ M8
M7 ─────────────────┘
M5 (independent)
```

### Build order

M1 → M2 → M7 → M8 → M3 → M4 → M5 → M6 → M9, one developer (Jack). Abandoned cart brings the most revenue, so cart tracking (M7) and the cart templates (M8) come right after the foundations (M1, M2).

M5 is independent and can move earlier if useful.

**Cut candidates if late** *(proposed default, pending confirmation)*: M5, template 4d (Upsell after 30 days), template 6b (Welcome 3-email series).

## Out of scope (v1.0)

- v1.1: #8 Branched post-purchase (If/Else already exists as Condition), #11 Nth order reward, #12 Negative review alert, #13 Unusual quantity alert, Slack action, order tags.
- v1.2: #10 Lifetime spend tiers, #14 Review reward.
- AI assistant in the Create dialog, Import, abandoned-cart reports / recovered revenue dashboard, rich email editor (v1 = plain textarea + WooCommerce template), A/B tests, SMS.

## Open questions

Everything below is already written into the plan as a *(proposed default, pending confirmation)*. Building goes ahead with the default unless you change it.

| # | Question | Proposed default | Where it's used |
|---|---|---|---|
| 1 | Preset names, timings, coupon values | As in the roadmap's [Template details (proposals)](../../../docs/ROADMAP-v1.0.md#template-details-proposals) table | Templates |
| 2 | Cart tracking on or off after install | Off (privacy; turned on in Settings) | M7 Settings, M8 |
| 3 | Send email recipient | Editable, defaults to `{{ customer.email }}` | M1 |
| 4 | Wait units | Minutes, hours, days (minutes help testing) | M2 |
| 5 | List page Runs / Last run columns + status tabs | In v1.0, built in M2 | M2 |
| 6 | "Most popular" sort | Static numbers from the demand table | M1 |
| 7 | Which M9 candidates go into v1.0 | None until picked | M9 |
