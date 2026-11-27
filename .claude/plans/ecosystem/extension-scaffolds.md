# Plan: Six extensions as scaffolds with fake triggers and actions

## Goal
YayMail, YayMagic, YaySMTP, YayPricing, YayWholesale and YayLoyalty each get a scaffold in `dev/ecosystem/<product>/` that registers every trigger, action, field and template of its extension in the v1.1 catalog (1–18 but 8), on a fake adapter, so every template can be used, activated and run on a dev site before any product code exists.

## Approach
Words as in `docs/extensions-roadmap.md` (catalog 1–18, the "Extension adds" columns). Public API only, on the names `api-names.md` settles (`Step`, `handle`, `settings`, `needs`), so `copy-scaffolds.md` copies each scaffold as it is.

- **Per product:** a module on `yaymatik_register` (with `requires_api`) declaring its triggers, actions, data items, data fields and templates (1–18 but 8; 19–21 are Pro, D7) in the chain format of extension-api.md §7. Step bodies and field values call the product's adapter; nothing else knows the product. Two classes per product: `<Product>_YayMatik` holds only the declarations on the public API; `<Product>_YayMatik_Fake_Adapter` is the part `copy-scaffolds.md` swaps for an empty adapter.
- **What each declares:** YayMail `yaymail_send_email` (templates 1–3); YayMagic `yaymagic_send_email` (4–6); YaySMTP data item `yaysmtp_email` (a failed email log entry, with `privacy_subjects`) and trigger `yaysmtp_email_failed` (7); YayWholesale customer fields role and status, cart fields minimum and missing amount, triggers request submitted, approved and rejected, action `yaywholesale_set_role` (9–12); YayLoyalty customer fields points and tier, triggers points earned, tier reached and points expiring, action `yayloyalty_award_points` (13–16); YayPricing order fields `yaypricing_rules` and `yaypricing_discount_total` (17, 18).
- **Email actions** pick their design from a select (field `design_id`) the fake adapter fills; `yaymagic-editor.md` adds the editor on that same field. No scaffold declares an editor here; YayMagic's is its own plan.
- **Fake adapter per product:** actions do the cheapest real thing and report it (`done()` outputs such as `points_added`, `role`); email actions return a simple design from `build`; fields return sample values. An action with a side effect skips it when `$step->is_test()` (D27), the pattern the README tells product teams to keep. The same fake adapters serve the dev site and `pnpm test:php`.
- **Loading (D25):** YayMatik loads `dev/ecosystem/*/` only with `YAYMATIK_IS_DEVELOPMENT` on, on `plugins_loaded` priority 20 as a later plugin would. `YAYMATIK_DEV_ECOSYSTEM_OFF` lists products to leave out. Never shipped (`/dev` is in `.distignore`).
- **Firing by hand (D25):** `./run.sh ecosystem-fire <trigger> [--wp-env]` calls `yaymatik_fire` with a sample payload.

## Checkpoints
1. Six scaffolds, fake adapters, loader, fire command — Risk: 2 — PHPUnit: each module registers with no diagnostic and its templates pass `TemplateCheck`. `./run.sh ecosystem-fire <trigger> [--wp-env]` starts a run that completes (on wp-env it defines `YAYMATIK_IS_DEVELOPMENT` through wp-cli `--exec`; `.wp-env.json` stays as it is). In the browser, on the local site in dev mode with `pnpm dev`: the 17 show in the library under their product's filter (5, 6, 16 with Pro on), Use template + Activate work. A short `dev/ecosystem/README.md`: loading, the off list, firing.

## Out of Scope
- The mark, delivery status, `Step::key()` and the cell tests (`email-stack.md`, `value-stack.md`).
- YayMagic's editor (`yaymagic-editor.md`).

## Decisions
Confirmed: D25, D26, D27.
- Template 8 left out: it needs tags (D16); YaySMTP's bounced trigger waits with it — confirmed (auto)
- P1.1 Templates 5, 6 and 16 start on Pro triggers (`customer_inactive`, `customer_order_count_reaches`), so they show only with YayMatik Pro, by the existing rule — confirmed
- P1.2 No skip rule: scaffolds live only until copy-scaffolds hands them to the product teams; `YAYMATIK_DEV_ECOSYSTEM_OFF` covers a clash. Replaces D25's "skipped when its product is active" — confirmed
- Fake adapters fill email designs as a select; no editor in this plan — confirmed (auto)

## Meta
- Target branch: feature/ecosystem-extension-scaffolds
- Base branch: feature/lane-a
- Unattended: yes
