# Working with Claude in YayMatik

For the team. Claude reads the skills; this page says how they fit together.

## Flow

`/wplan` writes a plan, `/wdev` builds it checkpoint by checkpoint, then one PR to `develop`.

## Plans

- Plans live in `.claude/plans/dev/<NNN>-<slug>.md` and are committed with the branch, so anyone can open the plan a commit names.
- Keep a plan after its PR merges: later plans read its decisions. Delete the folder's done plans once their version ships (git keeps them).
- A private plan is the exception: ask Claude for one and it goes in `.claude/local/plans/` (gitignored).
- Decisions that must outlive the plan go in `docs/workflow-core-decisions.md` or the doc they belong to.

## Decisions

Claude puts open decisions in one numbered list (`/wdecide`, or type `opens?`). Answer in one line: `3y` accept, `3n` reject, `3b` pick option b, `3?` explain, `go` write them to the plan.

## Review cost

`/wdev` reviews each checkpoint by its risk: Risk 1 `/code-review low`, Risk 2 `medium`, Risk 3 `high` and a stop for you.

## Your own adjustments

Write them in `.claude/local/wdev.md` or `.claude/local/wplan.md` (gitignored). The skills apply them on top. Example: `Review: low at every risk.` Keep them small; propose changes that help everyone as a PR to the skill itself.
