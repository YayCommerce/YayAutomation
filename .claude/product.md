---
name: YayMatik
slug: yaymatik
lanes:
  file: .claude/lanes/{letter}.md
  branches:
    lane: feature/lane-{letter}
    plan: feature/{plan}
    review: review/{letter}-{n}
  builder: /wdev {plan}
  sites: .claude/worktrees/{site}
  chat: claude
---

# YayMatik

Automation for WooCommerce: build workflows on a visual canvas or start from a template. Inspired by Shopify Flow, Klaviyo and AutomateWoo. PHP backend in `includes/`, React admin app in `apps/admin-page/`. Plans live in `.claude/plans/dev/<NNN>-<slug>.md`. The dev merges every PR.
