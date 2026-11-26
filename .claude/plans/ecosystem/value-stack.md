# Plan: Value stack — YayPricing, YayWholesale, YayLoyalty work together safely

## Goal
Core's side of the value stack, and a test per cell of its matrix proving a grant (role, points, discount) happens once per run and prices stay as the products compute them, with any mix of the three active or missing.

## Approach
Words and matrix as in `docs/extensions-roadmap.md` (value stack). Builds on `extension-scaffolds.md` and the local check from `email-stack.md`.

- **Grant once.** `Run::key()` returns a key stable across retries and replays of one step (run id + step id). Additive to the public `Run` (MINOR).
- **Fake adapters** of the three scaffolds dedupe their grant actions on `Run::key()`, as a real adapter must.
- **Cell tests** in `tests/Unit/Ecosystem/`, one per cell, on the fake adapters. Each checks grant once, prices untouched, run detail.
- `./run.sh check-ecosystem` gains the value stack's cells.

## Checkpoints
1. `Run::key()`, grant dedupe, cell tests, local check cells — Risk: 2 — every cell passes in `pnpm test:php` and `./run.sh check-ecosystem --wp-env`.

## Out of Scope
- Templates 19–20 (Pro, D7).
- Fields that declare their meta key (D10): first consumer is segments (D16).
- Search pickers, dependent options, repeater (D11): no template needs them.

## Decisions
Confirmed: D7, D10 (not built here), D11 (not built here), D22.

## Meta
- Target branch: feature/ecosystem-value-stack
- Base branch: feature/lane-a
- Unattended: yes
