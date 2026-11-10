# Plan: M1 — Customer emails + coupons

## Goal
Customer emails (rich editor, WooCommerce look, unsubscribe), single-use coupons, first-order conditions and the two First-order thank-you templates — M1 in two PRs.

## Decisions (Jack, 2026-10-16)
1. Send email To: default `{{ customer.email }}`, editable, one address.
2. Message: WordPress TinyMCE (`wp_enqueue_editor` + `wp.oldEditor.initialize`), Visual/Text tabs. Stored as HTML, `wp_kses_post` on save. Insert variable at the cursor (TinyMCE `insertContent`; textarea in Text tab). Rendered with `wpautop`. Clean init/destroy with React (panel open/close, step change). Dark mode: iframe content may stay light; note it, don't fix.
3. Look: WooCommerce email wrapper via WC mailer (logo, colors, footer).
4. Unsubscribe scope: per email address (guests too), all YayMatik customer emails; internal staff email ignores it.
5. Link: base64url(email) + HMAC(email, secret) token, no expiry, no plain email in the URL. Secret in option `yaymatik_unsub_secret`, created on install (not wp_salt). Table `{prefix}yaymatik_unsubscribes`. `List-Unsubscribe` + `List-Unsubscribe-Post` one-click headers.
6. Coupon: % off / fixed amount / free shipping; code = prefix (default YM) + 8 random chars; WC allowed emails = customer email; single use.
7. Thank-you templates: 10% / 30 days; free shipping / 30 days; trigger order completed + is first order.
8. `is_first_order`: no other paid order (processing/completed) besides this one, whatever this order's status. Refunded, cancelled, failed don't count. Two queries (customer ID, billing email), merged and de-duplicated. (Changed in A2 review.)
9. Preview: latest paid order, else made-up data labelled "Sample data". Send test → logged-in admin, subject prefixed `[Test]`. Preview / Send test never create a coupon; sample code `YMSAMPLE`.
10. "Most popular" fixed order: abandoned cart → checkout → thank-you → cross-sell → review → welcome → win-back → high-value.
11. Coupon via step outputs `{{ steps.<id>.code|amount|expires_at|box }}`; the `coupon.*` stub item goes.
12. New `html` field type (`wp_kses_post`, drawn by the rich editor) — internal email keeps the generic form.
13. Unsubscribe GET shows a page with an "Unsubscribe" button (link scanners); POST unsubscribes (button and one-click header).
14. Send email with no valid address after rendering → failed ("No valid email address"); unsubscribed → skipped, reason in run history.

## Approach

**HTML rendering (`Template` HTML mode).** The message is stored HTML already cleaned by `wp_kses_post`; literal HTML is kept, only values are escaped by context: `esc_html` in text, `esc_url` over the whole `href` / `src` value (a value after the start of the URL is `rawurlencode`d first), `esc_attr` in any other attribute. Block values (display callbacks, e.g. `order.items`, coupon box) are not re-escaped, so each block's display callback escapes its own values. Then `wpautop`; a paragraph holding only a block tag is unwrapped. Old plain-text messages (no HTML tags) are escaped as text before `wpautop`.

**Coupon box.** Generate coupon declares a `box` output flagged as a block, with a display callback (like `order.items`), rendered server-side at render time from the stored `code` / `amount` / `expires_at` — the HTML is never stored. Insert variable lists blocks in a "Blocks" group and inserts them as their own paragraph. No coupon → empty → left out.

**Coupon idempotency.** Coupon meta tags run id + node id; a re-run slice reuses the existing coupon.

**Preview sample data.** The data layer can't read an unsaved `WC_Order` (id 0) without reloading by id: `order.customer` is a Ref `customer:order:0` loaded via `wc_get_order(0)`, and `order.items` are Refs by item id (all 0 when unsaved) loaded through `WC_Order_Factory::get_order_item()`. So made-up data uses per-data-item sample arrays: each DataItem gives `sample()` (field => value, block fields as escaped sample HTML), read through a sample `Values`. A real recent order uses the normal Scope.

**Unsubscribe page.** Minimal self-contained HTML page (store name, button, result message), not wrapped in the theme, so it works on block and classic themes.

## Checkpoints

### PR A — backend (`jack/feature/m1a-emails-coupons` from develop)

1. ✅ Unsubscribe core — Risk 3 — Schema v3 adds `{prefix}yaymatik_unsubscribes` (email lowercased, unique, created_at); `yaymatik_unsub_secret` created on install (or lazily when missing). Token = HMAC(email, secret), checked with `hash_equals`; URL carries base64url(email) + token. Front handler: GET → self-contained page with an "Unsubscribe" button; POST → unsubscribe (button + one-click); bad link → "This link is no longer valid". PHPUnit: token, base64url round trip, email normalizing. Check the page on one block theme and one classic theme.
2. ✅ Fields + HTML rendering — Risk 3 — `customer.is_first_order` (two queries merged, de-duplicated), `customer.unsubscribed`; `html` field type; `Template` HTML mode + `wpautop` + block unwrap + plain-text fallback; internal email message → `html`, sample subject/message → PHP defaults. PHPUnit: is_first_order (guest, registered, refunded, cancelled, failed, registered customer whose earlier guest order used the same email → not first), sanitize, escaping in text / `href` / `src` / `alt` / other attributes, plain-text old message.
3. ✅ Generate coupon — Risk 3 — generic fields (type, amount show_if, expiry days, minimum spend, individual use, limit to customer, prefix default YM); outputs + `box` block; `run()` creates the WC coupon (prefix + 8 random chars, uniqueness retry, allowed email, usage limits 1, expiry, min spend, free shipping), no second coupon per run + step (the Executor never reruns a step). Validation: percent 1–100, fixed > 0, expiry whole days ≥ 1 or blank, prefix A–Z 0–9 - _ (uppercased, max 20). Expiry = end of day in the store timezone. Guests: allowed email = billing email. Outputs with their own display (`expires_at` date, `box`) render through `Scope::set_output_display()`. `FreeShipping::coupon_mode()` → `yayMatikMeta.wc.freeShippingCoupon` (`coupon` | `coupon_and_minimum` | `''`). Missing prefix key → YM, empty = none. Coupon meta `_yaymatik_run_id` / `_yaymatik_node_id` (Executor sets `run.id` / `run.step` in the Scope). PHPUnit with a fake coupon writer; coupon box escaping. Real coupons of each type checked on wp.test.
4. ✅ Send email — Risk 3 — To (one address), subject, heading, message; WC wrapper; unsubscribe footer link + `List-Unsubscribe` / `List-Unsubscribe-Post`; skipped when unsubscribed — checked on the final To address after variables are filled in, not the billing email — failed on no valid address; Modules manifest; `./run.sh meta-fixture`. PHPUnit: skip path, headers.
5. ✅ Preview + Send test REST, checks — Risk 3 — endpoints take node id + current config; sample = latest paid order, else per-item sample arrays labelled "Sample data"; coupon outputs → `YMSAMPLE`, no coupon created; Send test → logged-in admin, `[Test]` subject. check-orders: thank-you end to end on Mailpit (first order → coupon + email; second order → none; unsubscribed → skipped; one-click POST), old plain-text internal message renders escaped. check-orders prints each run's step statuses (with skip reason) and adds the unsubscribed case (customer email skipped as `unsubscribed`, run completed, later steps still run). Check scripts turn off WooCommerce's own emails while they run (`woocommerce_email_enabled_*` → false), so Mailpit only shows YayMatik mail. Docs: design §5/§6, `context-php-backend.md`.

### PR B — UI + templates (new branch from develop after A merges)

1. ✅ Rich editor widget — Risk 2 — `RichTextEditor` (`wp_enqueue_editor`, `wp.oldEditor.initialize` / `remove`), Visual/Text tabs, clean mount/unmount incl. StrictMode double mount, Insert variable at the cursor in both tabs; StepForm draws `html` fields; internal email picks it up; TS defaults removed. Note: iframe stays light in dark mode.
2. ✅ Send email panel — Risk 2 — custom form, Insert variable with "Blocks" group, unsubscribe note, Preview dialog (iframe, "Sample data" badge), Send test with error shown; remove stub entries from `pending.ts`.
3. ✅ Generate coupon + Condition + Activate warning — Risk 2 — generic form (delete `Form.tsx` / `schema.ts`), card "10% off, expires in 30 days"; warning in the Generate coupon panel when Free shipping is chosen and `wc.freeShippingCoupon` is `''` (link to WooCommerce → Shipping); when it is `coupon_and_minimum` (the only methods need coupon and minimum, "both"), the warning says the customer also needs the minimum order amount; Is true / Is false checked; clear wording for "no Generate coupon above".
4. ✅ Templates + library — Risk 2 — free shipping thank-you template: "Before you start" / preview note when `wc.freeShippingCoupon` is `''` (or the minimum-amount note for `coupon_and_minimum`); thank-you ×2 (is_first_order, `steps.coupon.*` + box, YM prefix, HTML message); cross-sell coupon paths updated (still locked by Wait); "Review the emails, then activate it"; template `workflow` key → "Other versions"; fixed "Most popular" order.
5. ✅ Verify — Risk 1 — `/wcheck`; mark M1 ✅/⏳ in ROADMAP-dev. Code review of the whole PR B diff: no bugs or dead code found; browser pass not run (Claude in Chrome not connected). Design §5/§6 updated; PR text in `018-pr-b.md`.

Each checkpoint: mark ✅, report, stop. No commit until Jack says.

## Follow-ups
- After PR A, separate small branch: flaky `pnpm build` — `vite-plugin-external` stand-ins in `apps/admin-page/node_modules/.vite_external/` are rewritten by both `pnpm dev` and `pnpm build`; give the build its own folder or upgrade / replace the plugin.
- Flaky build again (2026-10-20, after plan 019): `pnpm build` failed once with `"default" is not exported by node_modules/.vite_external_build/react-dom.js` (imported by sonner) while `pnpm dev` was running; the stand-in content was correct (`module.exports = ReactDOM;`) and two reruns passed. Fix later by upgrading or replacing `vite-plugin-external`.

- Preview / Send test permission (B2 review): the buttons show to anyone who can open the editor, but the REST routes need `manage_woocommerce` (`RestAPI::can_email()`). No change now: the page needs `manage_options` (`AdminPage::CAPABILITY`), and admins always have `manage_woocommerce`. Revisit if the page capability changes: send a capability flag in the meta and hide the buttons without it.

- WorkflowEditor issue wiring (server issues merged after autosave, "Needs setup" for server-only issues, the step's issue passed to its panel) has no editor-level test: unit tests cover `withServerIssues()` and `StepPanel`. Cover it in the e2e plan.

## Out of Scope
Wait and cross-sell templates going live (M2), unsubscribe page text in Settings (M7), admin list of unsubscribes, privacy exporter / eraser (M7.4), product grid, multiple To addresses, dark mode inside the TinyMCE iframe.

## Meta
- Target branch: `jack/feature/m1a-emails-coupons` (PR A), then a PR B branch
- Base branch: develop
