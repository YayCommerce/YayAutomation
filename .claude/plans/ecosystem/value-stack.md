# Plan: Value stack — YayPricing, YayWholesale, YayLoyalty work together safely

## Goal
Core's side of the value stack, and tests that prove a grant (role, points, discount) happens once per run and prices stay as the plugins compute them, with any mix of the three active or missing.

## Approach
Roadmap: `docs/extensions-roadmap.md` (group 2, catalog 9–20). Built on the recommendations below; revisit them before building.

- **Grant once.** `Run::key()` returns a key stable across retries and replays of one step (run id + step id). A grant action dedupes on it, so a retried or replayed step never grants twice. Additive to the public `Run` (MINOR).
- **Scaffolds** in `dev/ecosystem/yaypricing/`, `yaywholesale/`, `yayloyalty/`, as in `email-stack.md`: YayPricing (order and order-item fields, rule names as `options`), YayWholesale (request submitted / approved / rejected triggers, customer and cart fields, set-role action), YayLoyalty (points and tier fields, points earned / tier reached / points expiring triggers, award-points action), with templates 9–18. Bodies call the product adapter. Templates 19–20 are Pro and wait for D7.
- **Fake adapters** in `tests/Support/Ecosystem/` that act like each plugin.
- **Scenario tests** in `tests/Unit/Ecosystem/`: one per cell of the group 2 matrix (grant retried, run replayed, two workflows on one event, wholesale price and pricing rule on one order, each plugin removed mid-run). Each checks grant once, prices untouched, run detail.
- `./run.sh check-ecosystem` gains the three products' cells.

## Checkpoints
1. `Run::key()`, scaffolds, fake adapters, templates 9–18, scenario tests, local check cells — Risk: 2 — the matrix passes in `pnpm test:php` and `./run.sh check-ecosystem --wp-env`.

## Out of Scope
- Fields that declare their meta key (D10): first consumer is segments (D16).
- Search pickers, dependent options, repeater (D11): no template needs them.
- Copying to the product repos; real adapters.

## Decisions
Recommended, revisit before building: D7 (Pro templates 19–20), D11 (deferred, no consumer). Confirmed: D10 (not built here), D22.

## Meta
- Target branch: feature/ecosystem-value-stack
- Base branch: feature/lane-a
- Unattended: yes
