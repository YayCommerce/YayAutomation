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
