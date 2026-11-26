# Plan: Copy the six scaffolds to the product repos

## Goal
Each of YayMail, YayMagic, YaySMTP, YayPricing, YayWholesale and YayLoyalty gets its YayMatik extension scaffold from `dev/ecosystem/<product>/` in its own repo, so its team only writes the product adapter.

## Approach
Roadmap: `docs/extensions-roadmap.md` (D9, D13, D21, D22). Runs after Review ecosystem foundation, when every scaffold passes on its fake adapter.

- Copy each scaffold as it is, under the product's own namespace and text domain, with a README: what the adapter must do (the "Extension adds" column of the roadmap) and how to run `./run.sh check-ecosystem` from YayMatik against a site with the product installed.
- The copied adapter's methods `skip( 'Not built yet' )` and the module registers no template until the team marks it ready, so a product release with an unfilled scaffold changes nothing for its users. Code loads only when YayMatik is active and `supports()` what it needs.
- Each repo is a companion of this plan (`/wdev`): its own worktree on the same branch name, one PR into its default branch. The YayMatik PR opens last and lists the six.

## Checkpoints
1. Six companion PRs + the YayMatik PR — Risk: 2 — each PR passes its repo's own checks; `./run.sh check-ecosystem` on a site with the six plugins installed reports each cell "not built yet", none FAIL.

## Out of Scope
- The real adapters: each product team.
- Releasing any extension: each product, when its cells pass.
- Cross-repo CI.

## Decisions
Confirmed: D9, D13 (scaffold), D21, D22.

## Meta
- Target branch: feature/ecosystem-copy-scaffolds
- Base branch: feature/lane-a
