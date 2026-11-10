# Plan: Cross-sell / Upsell templates (frontend only)

## Goal

Ship the 4 Cross-sell / Upsell templates and the UI they need (Wait block, recommended-product data), stubbed and locked as "Coming soon" until the backend exists — same approach as plan 009.

## Approach

Backend is on hold (see plan 009 and memory `backend-on-hold`): no PHP / REST / DB.

**Pending stub (`blocks/core/pending.ts`):**
- Block `wait` — plugin `yayautomation`, new category `flow`.
- `order` fields: `order.has_cross_sells`, `order.has_upsells` (boolean, conditional); `order.cross_sell_products`, `order.upsell_products` (display-only, `conditional: false`; product grid: image, name, price, link; products already bought left out).

**Wait block.** Config `{ amount: '7', unit: 'minutes' | 'hours' | 'days' }`, amount a whole number ≥ 1 and at most a year (525600 min / 8760 h / 365 d); `normalize` repairs a numeric amount or unknown unit. Form: number + unit select. Summary "Wait 7 days" (plural-aware). `BlockSpec` gains optional `icon`; Wait uses `Clock` (replaces the kind icon on the card and in the panel header). Picker: YayAutomation appears in the action picker with a "Flow" section; Wait dimmed "Coming soon".

**Templates** (one family folder, category `post_purchase`, shared graph builder). All: Order status changed → Completed.

| | Condition 1 (before Wait) | Wait | Coupon | Email |
|---|---|---|---|---|
| 4a first-time buyers | paid_order_count = 1 AND has_cross_sells | 7 days | — | cross-sells |
| 4b repeat buyers | paid_order_count > 1 AND has_cross_sells | 7 days | — | cross-sells |
| 4c cross-sell + coupon | has_cross_sells | 14 days | 10%, 14 days | cross-sells |
| 4d upsell after 30 days | has_upsells | 30 days | 15%, 14 days | upsells |

After the Wait, with fresh data (review fix): Condition 2 `order.status` is `completed` AND still has cross-sells / upsells AND `customer.unsubscribed` is false → then [Generate coupon (from `defaults()`, no prefix)] → Send email. Copy: thank-you line + `{{ order.cross_sell_products }}` / `{{ order.upsell_products }}` (+ coupon lines for 4c / 4d). Timings and coupons from `docs/ROADMAP-v1.0.md` Template details.

## Checkpoints

1. Wait block, product data, 4 templates — Risk: 2 — stub entries, Wait folder (schema, summary, form, icon), block icon support, Flow picker section, 4 templates + registry. Test: library shows 4 new Post-purchase cards with Coming soon; previews render the Wait card with a clock and good spacing; Install locked; picker shows YayAutomation → Flow → Wait (Coming soon); `pnpm build` passes.

## Backend handoff (do when the backend roadmap lands, together with plan 009's)

- **PHP `Wait` action** (`wait`, plugin `yayautomation`, `CATEGORY_FLOW`): `sanitize`/`validate` mirror `blocks/actions/wait/schema.ts`. Pauses the run and resumes it: runs table + Action Scheduler resume job (roadmap 008 engine pieces 1–5: runs, wait/resume, Turn off / Apply / Delete cancel waiting runs). Conditions after a Wait read fresh data.
- **`OrderData`:** `has_cross_sells` / `has_upsells` (boolean) from the order's products' cross-sell / upsell ids minus products already in the order; `cross_sell_products` / `upsell_products` render an HTML product grid for email (image, name, price, link; plain-text fallback = names + links).
- **Ship together:** `send_email` and the product-grid fields (`order.cross_sell_products` / `order.upsell_products`). Send email allows pending variables only while it is pending itself, so PHP `send_email` without those fields makes the DEV template registry throw (and PHP would reject the tags anyway).
- **Known gap (not fixed):** Wait counts as an action for `no_action`, so trigger → Wait alone passes validation. Changing that is a shared TS + PHP validator rule; decide with the backend.
- **Cleanup:** delete the matching entries in `pending.ts` (and see plan 009's cleanup).

## Out of Scope

- Run history (overview runs table, run detail, list Runs / Last run columns), Turn off / Apply wait-cancel dialogs.
- All PHP / REST / DB.

## Meta

- Target branch: jack/templates/cross-sell-upsell (stacked on jack/templates/first-order-thank-you)
- Base branch: develop
