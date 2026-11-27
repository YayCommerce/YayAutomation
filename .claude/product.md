---
name: YayMatik
slug: yaymatik
lanes:
  file: .claude/lanes/{letter}.md
  branches:
    lane: feature/lane-{letter}
    plan: feature/{area}-{plan}
    review: review/{letter}-{n}
  builder: /wdev {area}/{plan}
  sites: .claude/worktrees/{site}
  gate: .claude/hooks/pr-merge-gate.sh
  chat: bash ./run.sh cw lane {letter}
---

# YayMatik

Automation for WooCommerce: build workflows on a visual canvas or start from a template. Inspired by Shopify Flow, Klaviyo and AutomateWoo. PHP backend in `includes/`, React admin app in `apps/admin-page/`. Lane plans live in `.claude/plans/<area>/<plan>.md` on the lane branch; `.claude/plans/dev/` stays the non-lane flow. The gate (`.claude/hooks/pr-merge-gate.sh`) lets plan PRs into a lane merge unprompted; the dev merges every other PR.
