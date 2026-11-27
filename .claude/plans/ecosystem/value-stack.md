# Plan: Value stack — YayPricing, YayWholesale, YayLoyalty work together safely

## Goal
Core's side of the value stack, and a test per cell of its matrix proving a grant (role, points, discount) happens once per run and prices stay as the products compute them, with any mix of the three active or missing.

## Approach
Words and matrix as in `docs/extensions-roadmap.md` (value stack). Builds on `extension-scaffolds.md` and the local check from `email-stack.md`.

- **Grant once (D28).** `Step::key()` returns the run-step row's key: the same across attempts of one step, and one per loop item once For each (V11.1) runs a step per item. Replay from a failed step (V11.7) must reuse the row's key. Additive to the public `Step` (MINOR).
- **Fake adapters** of the three scaffolds dedupe their grant actions on `Step::key()`, as a real adapter must.
- **Cell tests** in `tests/Unit/Ecosystem/`, one per cell, on the fake adapters. Each checks grant once, prices untouched, run detail.
- `./run.sh check-ecosystem` gains the value stack's cells.

## Checkpoints
1. `Step::key()`, grant dedupe, cell tests, local check cells — Risk: 2 — every cell passes in `pnpm test:php` and `./run.sh check-ecosystem --wp-env`.

## Out of Scope
- Templates 19–20 (Pro, D7).
- Fields that declare their meta key (D10): built with Get data (V11.2), its first consumer.
- Search pickers, dependent options, repeater (D11): no template needs them.

## Decisions
Confirmed: D7, D10 (not built here), D11 (not built here), D22, D28.

## Meta
- Target branch: feature/ecosystem-value-stack
- Base branch: feature/lane-a
- Unattended: yes
