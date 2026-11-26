# Plan: Six extensions as scaffolds with fake triggers and actions

## Goal
YayMail, YayMagic, YaySMTP, YayPricing, YayWholesale and YayLoyalty each get a scaffold in `dev/ecosystem/<product>/` that registers every trigger, action, field and template of its extension in the v1.1 catalog, on a fake adapter, so every template can be used, activated and run on a dev site before any product code exists.

## Approach
Words as in `docs/extensions-roadmap.md` (catalog 1–18, the "Extension adds" columns). Public API only, so `copy-scaffolds.md` copies each scaffold as it is.

- **Per product:** a module on `yaymatik_register` (after `supports()`) declaring its triggers, actions, data items, data fields and templates (1–18; 19–21 are Pro, D7) in the chain format of extension-api.md §7. Step bodies and field values call the product's adapter; nothing else knows the product.
- **Fake adapter per product:** actions do the cheapest real thing and report it (`done()` outputs such as `points_added`, `role`); email actions return a simple design from `build`; fields return sample values. The same fake adapters serve the dev site and `pnpm test:php`.
- **Loading (D25):** YayMatik loads `dev/ecosystem/*/` only with `YAYMATIK_IS_DEVELOPMENT` on, on `plugins_loaded` priority 20 as a later plugin would. `YAYMATIK_DEV_ECOSYSTEM_OFF` lists products to leave out. A scaffold is skipped when its product is active. Never shipped (`/dev` is in `.distignore`).
- **Firing by hand (D25):** `./run.sh ecosystem-fire <trigger> [--wp-env]` calls `yaymatik_fire` with a sample payload.

## Checkpoints
1. Six scaffolds, fake adapters, loader, fire command — Risk: 2 — PHPUnit: each module registers with no diagnostic and templates 1–18 pass the server template check. On wp-env: all 18 show in the library with their product, Use template + Activate work, `./run.sh ecosystem-fire` starts a run that completes.

## Out of Scope
- The mark, delivery status, `Run::key()` and the cell tests (`email-stack.md`, `value-stack.md`).
- YayMagic's editor (`yaymagic-editor.md`).

## Decisions
Confirmed: D25.

## Meta
- Target branch: feature/ecosystem-extension-scaffolds
- Base branch: feature/lane-a
- Unattended: yes
