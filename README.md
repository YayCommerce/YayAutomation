# YayMatik

WordPress plugin for automation workflows: a visual workflow builder plus a template library.

Scaffolded from [YayUiKits](../YayUiKits/README.md) conventions.

## Stack

- **React 18** + **TypeScript 5** + **Vite 6**, Tailwind CSS v4
- **YayUiKits** components (copied into `apps/admin-page/src/components/ui/`)
- **React Router 7** (hash-based), **Tanstack Query 5**
- **pnpm workspaces** (`apps/*`, `packages/*`)

## Repo Layout

```
apps/admin-page/   # React admin app
packages/          # Shared TS packages (@yaymatik/utils, /types)
includes/          # PHP backend (namespace YayMatik\)
  Engine/          # WP/WC glue: admin page, REST endpoints, script loading
  Workflow/        # Workflow Core: storage, definition, runtime, steps, data
yaymatik.php       # Plugin bootstrap
assets/dist/       # Build output (gitignored)
```

## Dev

```bash
pnpm install
./run.sh setup  # PHP deps: vendor/ + vendor-prefixed/ (pnpm dev runs it if missing)
pnpm dev        # Vite on :3020 — needs YAYMATIK_IS_DEVELOPMENT in wp-config.php
pnpm build      # typecheck + build
pnpm lint:fix
./run.sh release  # zip → release/yaymatik-automation-for-woocommerce.zip
```

```php
// wp-config.php
define( 'YAYMATIK_IS_DEVELOPMENT', true );
```

WP admin page: `/wp-admin/admin.php?page=yaymatik`

## Claude Harness

Skills: `/wplan` `/wdev` `/wcheck` `/wdist` `/wsetup`. Index: `.claude/CLAUDE.md`.
