# Plan: YayMagic scaffold as the showcase of an extension's own UI

## Goal
YayMagic's scaffold in `dev/ecosystem/yaymagic/` becomes working code that shows how a separate plugin adds its own UI to YayMatik: hook, module, action, editor iframe, workflow variables and sample in, value back, the sent email using it.

## Approach
Builds on the scaffold and adapter split from `email-stack.md`. Public API only, so `copy-scaffolds.md` copies it to the YayMagic repo as it is.

- **Copied part:** `yaymatik_register` → `supports( 'editor_iframe' )` → `module()`; action "Send email designed in YayMagic" with `email` (`build`, recipient `customer`, `validate` refuses order blocks without an order) and `editor` (`field: design_id`, button "Customize email"); templates 4–6.
- **Adapter part, stand-in here:** a minimal editor page. On `ready` → `init` it shows what came from the workflow (step config, sample order and customer ids, locale, the variables for this step); a click on a variable inserts `{{ path }}` into a plain body field; it posts `change` with `dirty`, `save` with the design id and its name as `label`, and `cancel`. In the YayMagic repo the adapter points at YayMagic's own editor instead.
- **README** in the folder: the flow step by step, pointing at the lines.
- Loaded as in `email-stack.md` (dev mode only, `plugins_loaded` 20, switch-off constant, skipped when the real plugin is active).

## Checkpoints
1. Showcase editor, templates 4–6, README, proof — Risk: 2 — PHPUnit for registration, `build_email` and `validate`; `./run.sh check-ecosystem` sends the design from a background run with variables rendered; `/wcheck`: open the editor, see `init`, insert a variable, save, panel shows the name, Preview matches.

## Out of Scope
- A builder: YayMagic has its own.
- Core changes; the other products.

## Decisions
E1 (opens in the v1 full-screen modal) and E4 (loading) recommended; settled in the prepare row.

## Meta
- Target branch: feature/ecosystem-yaymagic-scaffold
- Base branch: feature/lane-a
- Unattended: yes
