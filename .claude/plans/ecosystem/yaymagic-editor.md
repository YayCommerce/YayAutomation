# Plan: YayMagic editor — how an extension adds its own UI

## Goal
YayMagic's scaffold shows, in working code, how an extension adds its own UI to YayMatik: hook, module, action, editor iframe, workflow variables and sample in, value back, the sent email using it.

## Approach
Builds on YayMagic's scaffold from `extension-scaffolds.md` and the mark from `email-stack.md`. Public API only, so `copy-scaffolds.md` copies it as it is.

- **Extension part (copied):** `yaymatik_register` → `module()` (with `requires_api`); action "Send email designed in YayMagic" gains `editor` (`field: design_id`, button "Customize email") beside its `email` (`build`, recipient `customer`, `validate` refuses order blocks without an order).
- **Fake adapter part:** a minimal editor page (D24: opens in the v1 full-screen modal). On `ready` → `init` it shows what came from the workflow (step config, sample order and customer ids, locale, the variables for this step); a click on a variable inserts `{{ path }}` into a plain body field; it posts `change` with `dirty`, `save` with the design id and its name as `label`, and `cancel`. YayMagic's real adapter points at its own editor instead.
- **Variables:** `init.variables` comes from the same list as the editor's Insert variable for that step, so values from later control steps (a For each item, Set variable) reach the iframe with no API change. Checked, not rebuilt.
- **README** in the scaffold: the flow step by step, pointing at the lines.

## Checkpoints
1. Editor page, README, proof — Risk: 2 — PHPUnit for `build_email` and `validate`; `./run.sh check-ecosystem` sends the design from a background run with variables rendered; `/wcheck`: open the editor, see `init`, insert a variable, save, the panel shows the name, Preview matches.

## Out of Scope
- A builder: YayMagic has its own.
- Core changes; the other products.

## Decisions
Confirmed: D24, D25.

## Meta
- Target branch: feature/ecosystem-yaymagic-editor
- Base branch: feature/lane-a
- Unattended: yes
