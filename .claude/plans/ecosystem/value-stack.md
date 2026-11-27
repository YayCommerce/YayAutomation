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
- V1 `Step::key()` = the run-step row id and its run's start, `<row>.<unix>` (the mark's value), so a reset or restored backup never reuses one; '' outside a run (Preview, Send test) — confirmed (Review, was the bare row id)
- V2 Extension API 1.1.0: `Step::key()` and the email stack's `yaymatik_email_status` in its changelog; the YayWholesale and YayLoyalty scaffolds need `requires_api` 1.1 — confirmed (auto)
- V3 Retry and replay exist only from V11.7: a retried cell calls the grant's `handle` again on its own row, a replayed cell runs the run again from its start (rows keep their results) — confirmed (auto)
- V4 A condition reading the data of a plugin that's off is Skipped "<Plugin> isn’t active." and its path ends, as a step of that plugin is; before, the run failed on "Choose data for each rule.". The step index keeps each module's label for it (YayPricing has no steps). Activation still refuses such a workflow with the rule's settings error, not `missing_plugin` — confirmed (auto)
- V5 Cells = 4 scenarios (once, retried, replayed, two workflows on one event) × 8 mixes of the three products on or removed; every cell has the one order with a wholesale price and a rule discount — confirmed (auto)

## Meta
- Target branch: feature/ecosystem-value-stack
- Base branch: feature/lane-a
- Unattended: yes
