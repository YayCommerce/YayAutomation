# Plan: Extension scaffolds in the six product repos

## Goal
Each of YayMail, YayMagic, YaySMTP, YayPricing, YayWholesale and YayLoyalty gets a YayMatik extension scaffold in its own repo, wired to YayMatik's CI matrix, so its team only fills in step bodies.

## Approach
Roadmap: `docs/extensions-roadmap.md` (D9, D13, D22). Runs after Review ecosystem foundation.

- Per repo: a module registered on `yaymatik_register`, its steps declared with their labels, fields and `email` / `editor` keys and empty bodies that `skip( 'Not built yet' )`, its templates from the catalog copied from the fixture, and a README of what the team fills in (the "Extension adds" column of the roadmap).
- Code only loads when YayMatik is active and `supports()` what it needs, so a product release with an unfilled scaffold changes nothing for its users. A scaffold registers no template until its team marks it ready.
- Each repo is a companion of this plan (`/wdev`): its own worktree on the same branch name, one PR into its default branch. The YayMatik PR opens last, lists the six, and points the CI matrix at the repos.

## Checkpoints
1. Scaffolds in the six repos + CI matrix pointing at them — Risk: 2 — each PR passes its repo's CI; YayMatik's job runs each scaffold, reports "not built yet" cells, and still passes on fixtures.

## Out of Scope
- Filling the step bodies: each product team.
- Releasing any extension: each product, when its cells pass.

## Decisions
Confirmed: D9, D13 (scaffold), D22. Recommended, revisit: D21 (how CI reaches the repos).

## Meta
- Target branch: feature/ecosystem-extension-scaffolds
- Base branch: feature/lane-a
