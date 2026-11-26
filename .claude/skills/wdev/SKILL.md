---
description: Interactive dev workflow — develop an agreed plan checkpoint by checkpoint, with review between each.
---

# /wdev

Workflow - Interactive development. Use this once a plan is agreed (from `/wplan`).

**Read `.claude/docs/context-dev-convention.md` before starting.** If `.claude/local/wdev.md` exists, it is this dev's change to this skill: apply it on top.

---

## Step 0 — Get the Plan

Check `.claude/plans/` for a plan matching this task. Started by the Desk as `/wdev <area>/<plan>`: the plan is `.claude/plans/<area>/<plan>.md` and the branch `feature/<area>-<plan>`.

- **Found**: read it — goal, approach, checkpoints.
- **Not found**: run `/wplan` first. Do not proceed until a plan exists.

---

## Step 1 — Branch

Check the current branch. If it's `develop` or `main`: create a new branch first — never commit directly to those. Otherwise stay on current branch unless user asks.

Branch naming: `feature/...`, `fix/...`, `refactor/...`. Lowercase, hyphen-separated.

---

## Step 2 — Develop (Checkpoint Loop)

Work through the plan's checkpoints one at a time:

1. Announce which checkpoint you're starting
2. Implement it
3. Before asking for review:
   - **Format + typecheck**: run `pnpm build` from root (runs `tsc --noEmit && vite build`)
   - **Dev server**: tell the user to run `pnpm dev` to verify in the browser
   - Comments only for a quirk, a workaround or why not the obvious way; never decisions, history or restating code
   - Search for every symbol you added; delete any with no caller
4. Present a summary:

> **Checkpoint 1 complete: \<name\>**
>
> Changes:
> - `path/to/file.tsx` — description
>
> Dev server: `pnpm dev`
>
> To test: \<specific steps\>
>
> Ready for review. Should I continue to checkpoint 2?

5. **Review depth follows the checkpoint's Risk tier:**
   - **Risk 1** (UI, refactor): show diff map and run `/code-review low`. Auto-continue when build is clean.
   - **Risk 2** (feature, new component, form): run `/code-review medium`. Fix findings or note each skipped one. Auto-continue when nothing open.
   - **Risk 3** (hard to reverse): run `/code-review high`, then stop for manual review. Run `/wcheck` before marking done.

Put decisions to the user with `/wdecide`.

---

## Done

After all checkpoints approved:

1. Tick this plan's row in the lane file (`[x]` and the PR number) in the same PR, so merged means ticked. A plan with a lane row targets that lane's branch (`feature/lane-<letter>`); otherwise `develop`.
2. A plan that also changes other plugins (YayWholesale, YayMail, …): each companion repo in its own worktree on the same branch name, one PR per repo into that repo's default branch. While a companion PR waits, run `yay lane report <plan> parked "<which PR waits>"`.
3. Push: `git push -u origin <branch>`
4. Create PR: `gh pr create` to the branch from step 1. With companion PRs, this PR opens last and lists them.
5. Poll CI: `gh pr checks <number>` every 60s until pass or fail
6. If CI fails: `gh run view --log-failed`, fix, push, re-poll
7. Report:

> All done. PR #\<number\> — CI passed. / CI failed on \<job\>: \<reason\>.
