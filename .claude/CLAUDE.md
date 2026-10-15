# YayMatik

WordPress plugin: automation workflows + template library. Inspired by Shopify Flow, Klaviyo, AutomateWoo.

## Conventions

- Bottom line up front. Short sentences, no preamble.
- Build only what the current plan asks for.
- **pnpm only.** Never npm or yarn.

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
