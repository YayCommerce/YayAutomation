# Plan: First-order thank-you template (frontend only)

## Goal

Ship the UI for the two First-order thank-you templates and the blocks they need (Send email, Generate coupon, boolean criteria, per-step data), with the backend stubbed and locked as "Coming soon" until PHP exists.

## Approach

Backend is on hold (waiting for the leader's backend roadmap): no PHP / REST / DB changes in this plan. Everything the backend must add later is in **Backend handoff** below.

**Pending backend stub.** `blocks/core/pending.ts` holds placeholder meta for what PHP doesn't send yet:
- blocks `send_email`, `generate_coupon`;
- data fields `customer.paid_order_count` (number), `customer.unsubscribed` (boolean), new `coupon` item (`code`, `amount`, `expiry_date`, `description`);
- operators `boolean` → `is_true` / `is_false`.

`data.ts` and `defineBlock` merge the stub under PHP meta (PHP wins). `isPending(blockId | fieldKey)` = PHP meta lacks it. When PHP ships, entries stop being pending by themselves; nothing to toggle.

**Boolean fields.** `DataFieldType` gains `boolean`; operator labels "Is true" / "Is false", no value input (`needsValue`); criteria form + schema accept it.

**Action-provided data.** `BlockMeta.provides` also for actions (`generate_coupon` → `coupon`); `BlockMeta.category` gains `coupon`. Data available at a step = trigger `provides` + `provides` of its ancestor actions (graph is a tree) + `always`. Used by `validateWorkflow()` (`missing_data`) and a per-step `VariableScope` ("Add a variable" lists Coupon only below a Generate coupon).

**Picker.** Pending actions show under WooCommerce (Email, Coupons), dimmed, tooltip "Coming soon", not pickable.

**Send email block.** Config `{ to, subject, heading, message }`, `to` default `{{ customer.email }}`. Form: To / Subject / Heading / Message with Add variable; note "An unsubscribe link is added automatically"; **Preview** and **Send test** buttons disabled "Coming soon". Summary "Send email to *to*: *subject*".

**Generate coupon block.** Config `{ discount_type: percent | fixed_cart | free_shipping, amount, expiry_days, minimum_spend, individual_use, limit_to_customer (default true), code_prefix }`. Segmented discount type; amount hidden for free shipping; hint "Use {{ coupon.code }} in a later email". Summary "10% off, expires in 30 days" / "Free shipping, expires in 30 days".

**Templates.** `first-order-thank-you-discount` (10 %, 30 days) and `first-order-thank-you-free-shipping` (30 days). Graph: Order status changed (to: Completed) → Condition (`customer.paid_order_count` = 1 AND `customer.unsubscribed` is false) → then Generate coupon → Send email (thank-you text + coupon code / value / expiry tags). Timings and values from `docs/ROADMAP-v1.0.md` Template details.

**Template metadata + library.** `WorkflowTemplate` category becomes an id `recover_carts | post_purchase | customer_lifecycle | alerts` (High-value order alert → `alerts`). Library (Shopify Flow style): filter pills under the search, "Plugins" (checkbox, Installed / Not installed, search) and "Categories" (radio with "All" first; only categories with templates); "Clear all" beside the pills only while a filter is set; dashed when empty, solid "Label: value" when set; "N templates" count above the grid; "Coming soon" badge on pending templates. Preview page About unchanged (user decided: no Other versions, no Steps). Install disabled "Coming soon" while any of the template's blocks / fields is pending.

## Checkpoints

1. Foundations — Risk: 2 — stub + meta merge, `isPending`, boolean type in criteria, action `provides` + per-step availability + VariableScope, `coupon` picker section, pending actions disabled. Test: criteria builder shows new fields + boolean operators; picker shows the two "Coming soon" actions.
2. Blocks, templates, library — Risk: 2 — Send email + Generate coupon folders (schema, summary, form), BlockId union, 2 templates, category ids (family / presetLabel dropped by user), library filters, Install lock. Test: both templates preview with correct cards, Install locked, `pnpm build` passes, dev registry doesn't throw.

## Backend handoff (do when the backend roadmap lands)

Trigger: user asks "làm backend cho plan 009" (or similar). Reconcile with the new backend roadmap first; where it differs, it wins.

- **PHP `SendEmail` action** (`send_email`, WooCommerce · email): `sanitize`/`validate` mirror `blocks/actions/send-email/schema.ts` (`to` = comma list of emails or `{{ x.y }}` tags, required; blank `heading` = subject). `run`: renders each `to` part, sends via WC mailer from the WC sender, wraps with `heading`, appends the unsubscribe footer, skips (logs) a recipient who is unsubscribed.
- **Preview / Send test (PHP + FE):** REST `POST /email/preview` (config + sample data from the last matching order → wrapped HTML) and `POST /email/test` (sends to the current admin). FE still to build: replace `ComingSoonButton` in `blocks/actions/send-email/Form.tsx` with a Preview dialog (iframe of the HTML) and a Send test mutation + toast; API client in `api/`.
- **PHP `GenerateCoupon` action** (`generate_coupon`, WooCommerce · coupon, `provides: ['coupon']`): creates a `WC_Coupon`: code = `code_prefix` + 6 random uppercase letters/digits (the form's hint shows `THANKS-7K2P9Q`), usage limit 1, `email_restrictions` = customer email when `limit_to_customer`, expiry = now + `expiry_days` (blank = none), `minimum_amount`, `individual_use`, free shipping type sets `free_shipping` with amount 0. Exposes it to later steps' context. `sanitize`/`validate` mirror `blocks/actions/generate-coupon/schema.ts`. Add `CATEGORY_COUPON`.
- **Plugins:** `Engine\Plugins` adds `yayautomation` (label YayAutomation, always active); Condition (and later Wait) reports `plugin: yayautomation` in `BlockRegistry::to_client()`. FE stub: `plugins` + `blockExtras` in `pending.ts`.
- **Engine:** actions' `provides` in `BlockRegistry::to_client()` + Runner context; `WorkflowValidator` per-step availability (mirror `model/validation.ts`).
- **Data:** `DataItem::TYPE_BOOLEAN` + `Operators` (`is_true` / `is_false`, no value); `customer.paid_order_count` (paid statuses, by user id else billing email); `customer.unsubscribed` + unsubscribe table / signed link / page; `CouponData` item: `code`, `amount` (displays with its unit: "10%", "$5.00", "Free shipping" — the template email reads "here is {{ coupon.amount }} off"), `expiry_date` (WP date format; blank when none), `description` (same text as the Generate coupon summary, e.g. "10% off, expires in 30 days"). `Criteria` must accept boolean rules with an empty value (the template ships `customer.unsubscribed` `is_false`).
- **Gate:** ship SendEmail, GenerateCoupon, the coupon / customer fields and the validator's action `provides` together — the templates unlock (Install) as soon as their blocks and fields are in PHP meta.
- **Dedupe (decision needed — ask the user before building):** same order going back to Completed must not send a second coupon. Options: order meta per workflow, trigger claim per (order, status), or runs table "already sent" (M2).
- **Messages:** PHP `missing_data` message should match TS `model/validation.ts` ("…neither the trigger nor a step above provides.").
- **Cleanup:** delete the matching entries in `blocks/core/pending.ts`; when empty, delete the file and the merge code, point `data.ts` / `defineBlock` back at `window.yayAutomationMeta.automation` (type `blocks` as a full Record again), and switch `unknownVariableAllowingPending` callers to `unknownVariable`. Pending UI ("Coming soon", Install lock) then disappears by itself — verify in the browser.

## Changes during development (agreed with the user)

- About panel unchanged: Other versions and Steps were built, then removed. `family` / `presetLabel` removed.
- Library filters reworked to Shopify Flow style (see Template metadata + library).
- **Canvas spacing** (outside the plan's original scope): `spacedPositions()` in `shared/canvas/flow.ts` pushes steps down so each sits ≥ 96px below its parent's measured bottom; used by the editor canvas and `ReadOnlyCanvas`. Stored positions unchanged.
- **YayAutomation plugin** (stubbed): plugin `yayautomation` + Condition grouped under it, so templates show the WooCommerce and YayAutomation icons like Shopify Flow's Shopify + Flow. Panel header reads "YayAutomation condition".

## Not verified yet

- Dev template registry check (only runs under `pnpm dev`; throws if a template graph has issues).
- `/wcheck` browser pass (light/dark, library filters, picker, preview, canvas spacing).
- Nothing committed; plan and memory are local to this machine (gitignored / outside the repo).

## Out of Scope

- All PHP / REST / DB (see Backend handoff).
- Other templates, Wait block, run history.
- Rich email editor (Message stays a textarea).

## Meta

- Target branch: jack/templates/first-order-thank-you
- Base branch: develop
