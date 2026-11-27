# Plan: Copy the six scaffolds to the product repos

## Goal
YayMail, YayMagic, YaySMTP, YayPricing, YayWholesale and YayLoyalty each get their scaffold from `dev/ecosystem/<product>/` in their own repo, so each product team only writes the real adapter.

## Approach
Words as in `docs/extensions-roadmap.md` (D9, D13, D21, D22). The chunk's closing chore: runs after Review ecosystem foundation merges, with no new planning.

- Copy each scaffold as it is, under the product's own namespace and text domain, with its README: what the real adapter must do (the "Extension adds" column of the roadmap), the `is_test()` rule (D27), how to run `wp yaymatik extension check` and the PHPStan stubs (`extension-check.md`), and how to run `./run.sh check-ecosystem` from YayMatik against a site with the product installed.
- The fake adapter is not copied: an empty adapter takes its place, whose steps `skip( 'Not built yet' )`, and the extension registers no template until the team marks it ready. A product release with an unfilled scaffold changes nothing for its users. The extension loads only when YayMatik is active, and its module declares `requires_api`.
- Each repo is a companion of this plan (`/wdev`): its own worktree on the same branch name, one PR into its default branch. The YayMatik PR opens last and lists the six. While companion PRs wait for their repos' owners, the Builder reports `parked`; nothing else stops it.

## Checkpoints
1. Six companion PRs + the YayMatik PR — Risk: 2 — each PR passes its repo's own checks; `./run.sh check-ecosystem` on a site with the six products installed reports each cell "not built yet", none FAIL.

## Out of Scope
- The real adapters: each product team.
- Releasing any extension: each product, when it is ready.
- Cross-repo CI.

## Decisions
Confirmed: D9, D13, D21, D22, D26, D27.

## Meta
- Target branch: feature/ecosystem-copy-scaffolds
- Base branch: feature/lane-a
- Unattended: yes
