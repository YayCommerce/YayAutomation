# YayAutomation

WordPress plugin: automation workflows + template library. UX reference: Shopify Flow.

## Conventions

- Bottom line up front. Short sentences, no preamble.
- Build only what the current plan asks for.
- **pnpm only.** Never npm or yarn.

## Layout

- `yay-automation.php` — plugin bootstrap
- `includes/` — PHP backend, namespace `YayAutomation\`
- `apps/admin-page/` — React admin app
- `dev/` — team scripts (`./run.sh`), personal `.env.local`; not shipped
- `packages/` — shared TS (`@yay-automation/utils`, `@yay-automation/types`)

## Commands

```bash
./run.sh        # team scripts in dev/scripts (setup, release, claude-workflow / cw)
pnpm dev        # Vite :3020
pnpm build      # typecheck + build
pnpm lint:fix
```

Admin page: `/wp-admin/admin.php?page=yay-automation`

## Docs

Read the matching doc before a non-trivial task.

| Doc                                      | Triggers                                                             |
| ---------------------------------------- | -------------------------------------------------------------------- |
| `.claude/docs/context-dev-convention.md` | branch, workflow, lint, format, plan, checkpoint, verify, dev server |
| `.claude/docs/context-ui-components.md`  | component, ui, variant, theming, dark mode, YayUiKits                |
| `.claude/docs/context-php-backend.md`    | PHP, REST, endpoint, admin menu, enqueue, localize, dev mode         |
| `.claude/docs/ops-release.md`            | release, version, zip, dist                                          |

**Docs rule:** reference paths, don't copy code. Document only what exists.
