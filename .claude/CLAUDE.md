# YayMatik

WordPress plugin: automation workflows + template library. Inspired by Shopify Flow, Klaviyo, AutomateWoo.

## Mandatory conventions
- Bottom line up front. Short sentences, active voice, no preamble or trailing summaries.
- **pnpm only.** Never npm or yarn.
- Code comments: only when needed and worth their upkeep (a quirk, a workaround, why not the obvious way); length is fine. Never record decisions, plans, history or discussion (decisions go to docs/workflow-core-decisions.md), and never restate the code.
- Docblocks: one line of purpose at most. Types only: `@param Type $name`, no text that repeats the name. phpcs needs a class comment, and every @param with its type once a function has a docblock.
- No plan or checkpoint numbers in code comments or code.
- Keep CLAUDE.md lean: no net growth; detail goes in a .claude/docs file behind an existing pointer.

## Scope and dead code
- Write only what the current task uses. No helpers, options, params, filters, hooks or catalog keys "for later".
- Before finishing, search for every function, class, constant, option and export you added; delete any with no caller. A test alone is not a caller.
- Never leave commented-out code. Git keeps history.
- No fallbacks or backward-compat code unless the task asks. Before v1.0 nothing has shipped: data saved by earlier dev builds is reset, not migrated.
- Unused code you notice outside the task: list it at the end of your report; don't delete it in the same change.

## Layout

- `yaymatik.php` — plugin bootstrap
- `includes/` — PHP backend, namespace `YayMatik\`
- `apps/admin-page/` — React admin app
- `dev/` — team scripts (`./run.sh`), personal `.env.local`; not shipped
- `packages/` — shared TS (`@yaymatik/utils`, `@yaymatik/types`)

## Commands

```bash
./run.sh        # team scripts in dev/scripts (setup, release, claude-workflow / cw)
pnpm dev        # Vite :3020
pnpm build      # typecheck + build
pnpm lint:fix
```

Admin page: `/wp-admin/admin.php?page=yaymatik`

## Docs

Read the matching doc before a non-trivial task.

<!-- prettier-ignore -->
| Doc | Triggers |
|---|---|
| `.claude/docs/context-dev-convention.md` | branch, workflow, lint, format, plan, checkpoint, verify, dev server |
| `.claude/docs/context-ui-components.md` | component, ui, variant, theming, dark mode, YayUiKits |
| `.claude/docs/context-php-backend.md` | PHP, REST, endpoint, admin menu, enqueue, localize, dev mode |
| `.claude/docs/ops-release.md` | release, version, zip, dist |
| `docs/ROADMAP-v1.0.md` | roadmap, v1.0, milestone, template plan (planned work, not yet built) |
| `docs/ROADMAP-dev.md` | dev roadmap, build order, tasks, F0/F1/F2, v1.1, v1.2, v2.0 (planned work, not yet built) |
| `docs/workflow-core-design.md` | workflow core, runtime, runs, steps, data layer, templates, modules, health check, decisions (F1 built, rest planned) |
| `docs/workflow-core-decisions.md` | why, decision, rationale, rejected options, trade-off, explain to dev |

**Docs rule:** reference paths, don't copy code. Document only what exists.
