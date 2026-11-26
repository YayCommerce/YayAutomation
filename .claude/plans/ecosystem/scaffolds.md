# Plan: Scaffold all six extensions with fake triggers and actions

## Goal
YayMail, YayMagic, YaySMTP, YayPricing, YayWholesale and YayLoyalty each get an extension scaffold in `dev/ecosystem/<product>/` that registers every step, field and template of the v1.1 catalog, with a fake adapter behind it, so every template can be used, activated and run on a dev site before any product code exists.

## Approach
Roadmap: `docs/extensions-roadmap.md` (catalog 1–18, the "Extension adds" columns). Public API only, so `copy-scaffolds.md` copies each folder as it is.

- **Per product:** a module on `yaymatik_register` (after `supports()`) declaring its triggers, actions, data items, data fields and catalog templates (1–18; 19–21 are Pro, D7) in the chain format of extension-api.md §7. Step bodies and field values call the product's adapter; nothing else knows the product.
- **Fake adapter per product:** actions do the cheapest real thing and report it (`done()` outputs such as `points_added`, `role`), email actions return a simple design from `build`; fields return sample values; each trigger fires through `yaymatik_fire` from `./run.sh ecosystem-fire <trigger> [--wp-env]`, so a run can be started by hand. In a product repo the team swaps the adapter for real code.
- **Loading (E4):** YayMatik loads `dev/ecosystem/*/` only with `YAYMATIK_IS_DEVELOPMENT` on, on `plugins_loaded` priority 20 as a later plugin would. `YAYMATIK_DEV_ECOSYSTEM_OFF` lists products to leave out (the "plugin missing" cells). A stand-in is skipped when its real plugin is active. No symlink or wp-env mapping; never shipped (`/dev` is in `.distignore`).

## Checkpoints
1. Six scaffolds, fake adapters, loader, fire command — Risk: 2 — PHPUnit: each module registers with no diagnostic and each of templates 1–18 passes the server template check. On wp-env: all 18 show in the library with their plugin, Use template + Activate works, `./run.sh ecosystem-fire` starts a run that completes.

## Out of Scope
- The email mark, delivery status, grant-once key and scenario matrices (`email-stack.md`, `value-stack.md`).
- The YayMagic editor showcase (`yaymagic-scaffold.md`).

## Decisions
E4 (loading) recommended; settled in the prepare row.

## Meta
- Target branch: feature/ecosystem-scaffolds
- Base branch: feature/lane-a
- Unattended: yes
