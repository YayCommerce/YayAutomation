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

- Stop hook (`.claude/hooks/stop-check.sh`) runs on uncommitted changes and blocks on failure: `pnpm build` + `pnpm test` when `apps/` or `packages/` change, `php -l` + phpcs on changed PHP files. A change set that passed is skipped.
- `pnpm lint:fix` and `pnpm lint:php:fix` before commit. CI (`.github/workflows/ci.yml`) runs lint, lint:php, test and build on every PR to `develop`.
- A change in behavior adds or updates a test (Vitest, `*.test.ts` next to the code). Tests read PHP's meta from `apps/admin-page/src/test/fixtures/meta.json`; run `./run.sh meta-fixture` after changing what PHP sends.
- UI change → `/wcheck`. REST change → `fetch('/wp-json/yay-automation/v1/…')` in console.

## Workflows folder (`apps/admin-page/src/routes/workflows/`)

- `api/` REST client + query hooks · `model/` graph and validation (no React code; block schemas come through `blocks/`, whose registry also loads the block forms) · `blocks/` one folder per block · `templates/` one folder per template · `pages/` one folder per page · `shared/` code used by two or more pages.
- Imports go one way: `pages/` → `shared/` → `api/`, `model/`, `blocks/`, `templates/`. Pages never import from another page's folder; `shared/` never imports `pages/`; `blocks/` and `templates/` never import `pages/` or `shared/`.
- Bottom layer: `model/` may import `blocks/`; `templates/` may import `model/` and `blocks/`; `blocks/` and `api/` import nothing else from `workflows/`.
- New template = new folder in `templates/` + one line in `templates/index.ts`. In development the registry throws if a template's graph has issues.
- New block = PHP class in `includes/Engine/Blocks/` + TS folder in `blocks/` (schema, summary, optional form) + one line in each registry. Labels, types and data needs come from PHP (`blocks/core/data.ts` reads the data registry).
- `blocks/core/fields/` holds what block forms share: `ConfigField`, `VariablePicker` / `AddVariable`, and the contexts the editor sets per step (`feedback`, `variableScope`).
- Page-only components live in that page's folder; move to `shared/` only when a second page needs them.

