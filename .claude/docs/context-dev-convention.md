# Dev Convention

## Skills

`/wplan` plan (no code) → `/wdev` build by checkpoint → `/wcheck` browser verify. `/wdist` release. `/wsetup` env check.

## Branch

Stay on current branch. If on `main`, branch first: `feature/…`, `fix/…`, `refactor/…` off `main`.

Plans: `.claude/plans/<NNN>-<slug>.md`.

## Dev Server

Add `define( 'YAY_AUTOMATION_IS_DEVELOPMENT', true );` to `wp-config.php`, then `pnpm dev`. Port override: `YAY_AUTOMATION_VITE_PORT`.

Without it, WP loads the prod build in `assets/dist/admin-page/`.

## Verify

- `pnpm build` must pass — Stop hook in `.claude/settings.json` enforces build + `php -l`.
- `pnpm lint:fix` before commit.
- UI change → `/wcheck`. REST change → `fetch('/wp-json/yay-automation/v1/…')` in console.

## Workflows folder (`apps/admin-page/src/routes/workflows/`)

- `api/` REST client + query hooks · `model/` graph and validation (no React) · `blocks/` one folder per block · `templates/` one folder per template · `pages/` one folder per page · `shared/` code used by two or more pages.
- Imports go one way: `pages/` → `shared/` → `api/`, `model/`, `blocks/`, `templates/`. Pages never import from another page's folder; `shared/` never imports `pages/`; `blocks/` and `templates/` never import `pages/` or `shared/`.
- Bottom layer: `model/` may import `blocks/`; `templates/` may import `model/` and `blocks/`; `blocks/` and `api/` import nothing else from `workflows/`.
- New template = new folder in `templates/` + one line in `templates/index.ts`. In development the registry throws if a template's graph has issues.
- Page-only components live in that page's folder; move to `shared/` only when a second page needs them.

