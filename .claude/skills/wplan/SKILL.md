---
description: Planning workflow — gather requirements, research the codebase, set goals and checkpoints, confirm the plan with the user, then hand off to /wdev. Use this before any non-trivial task when the approach is unclear or needs alignment. Does NOT write code.
---

# /wplan

Workflow - Planning only. Use this to define a task before developing it.

**Do not write any code or implement any solution. This skill ends by saving the confirmed plan and immediately starting `/wdev`.**

**Read `.claude/docs/context-dev-convention.md` before starting.** If `.claude/local/wplan.md` exists, it is this dev's change to this skill: apply it on top.

---

## Step 1 — Gather Requirements

Ask the user clarifying questions until the task is unambiguous:

- What is the goal / expected outcome?
- What is the current behavior (if a bug or change)?
- Which pages, components, or areas are involved?
- Are there design references, mockups, or prior discussions?
- Are there constraints: performance, scope limits?
- Any edge cases to handle or explicitly exclude?

Do not proceed until you have a clear, concrete picture of what needs to be built. Put open decisions to the user with `/wdecide`.

---

## Step 2 — Research

Gather any information needed to write a confident plan:

- Read relevant source files, existing patterns, or related components
- List `.claude/docs/` and read any doc matching this task's topic
- Check `apps/admin-page/src/components/ui/` for existing primitives to reuse
- Identify risky areas or non-obvious constraints

Summarize findings briefly before moving on. If research reveals new ambiguities, return to Step 1.

---

## Step 3 — Write the Plan

Draft a plan with these sections:

```
## Goal
One-sentence summary.

## Approach
High-level technical approach — what changes, why, and how they fit together.

## Checkpoints
1. <Checkpoint name> — Risk: 1|2|3 — <what it delivers, what can be tested>
2. ...

## Out of Scope
Anything explicitly excluded.
```

**Checkpoint rules:**
- Default to a single checkpoint unless the task clearly warrants splitting
- Only use multiple checkpoints when: more than 10 files change AND the task takes more than 10 minutes AND there is critical risk
- When in doubt, one checkpoint is better than two

**Risk tier:**
- **1** — pure code, refactor, or UI-only change
- **2** — feature logic, new component, form/validation change
- **3** — data migration, security, hard to reverse

---

## Step 4 — Confirm and Hand Off

Present the plan to the user and ask for a single confirmation:

> Here's the plan. Confirm and I'll save it and start development — or let me know what to adjust.

**Wait for approval.** On confirmation:

1. Save the plan to `.claude/plans/dev/<NNN>-<slug>.md` (next free number) and commit it: `plan <NNN>: <slug>`. For a lane row, save it instead to `.claude/plans/<area>/<slug>.md` on the lane branch and link the row to it. Only when the user asks for a private plan, save it in `.claude/local/plans/` instead:

```markdown
# Plan: <title>

## Goal
...

## Approach
...

## Checkpoints
1. <name> — Risk: <1|2|3> — ...

## Out of Scope
...

## Decisions
...

## Meta
- Target branch: <current branch>
- Base branch: develop
```

2. **Immediately continue into `/wdev`** — do not stop or ask the user to run it manually.
