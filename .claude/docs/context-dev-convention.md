# Dev Convention

## Skills

`/wplan` plan (no code) → `/wdev` build by checkpoint → `/wcheck` browser verify. `/wdist` release. `/wsetup` env check.

## Branch

Stay on current branch. If on `develop`, branch first: `feature/…`, `fix/…`, `refactor/…` off `develop`.

Busy checkout (e.g. a bug fix mid-feature): `git worktree add`, then in it `./run.sh setup && pnpm install && pnpm build && WP_ENV_PORT=8920 pnpm wp-env start` and `./run.sh seed --wp-env`.

Plans: `.claude/plans/dev/<NNN>-<slug>.md`, committed. Team practice: `.claude/README.md`.

## Dev Server

Add `define( 'YAYMATIK_IS_DEVELOPMENT', true );` to `wp-config.php`, then `pnpm dev`. Port override: `YAYMATIK_VITE_PORT`.

Without it, WP loads the prod build in `assets/dist/admin-page/`.

## Verify

- Stop hook (`.claude/hooks/stop-check.sh`) checks the branch's changes and blocks on failure: `pnpm build` + `pnpm lint` + `pnpm test` when `apps/` or `packages/` change, `php -l` + PHPUnit + phpcs when PHP changes.
- `pnpm lint:fix` (skips ESLint fixes in `components/ui/`, the YayUiKits copies) and `pnpm lint:php:fix` before commit. CI: `.github/workflows/ci.yml`.
- Two editions: YayMatik Pro code lives only in `includes/Pro/` and `apps/admin-page/src/pro/` (mirroring the free paths); free code never imports it. `pnpm test:lite` / `pnpm test:php:lite` run as the free plugin (meta fixture `meta-lite.json`). wp-env runs Pro with a license from `dev/wp/mu-plugins/yaymatik-dev-license.php`; `wp option update yaymatik_dev_edition lite` runs it as the free plugin.
- A change in behavior adds or updates a test: Vitest (`*.test.ts` next to the code) or PHPUnit (`tests/Unit/`, mirrors `includes/`, no WordPress loaded; WP-bound code is checked with wp-cli). Tests read PHP's meta from `apps/admin-page/src/test/fixtures/meta.json`; run `./run.sh meta-fixture` after changing what PHP sends.
- UI change → `/wcheck`. REST change → `fetch('/wp-json/yaymatik/v1/…')` in console.
- Extension API: `dev/example-extension/` is a plugin using every piece of it (installed as `acme-rewards` on wp-env; `pnpm wp-env start` after pulling a `.wp-env.json` change). `./run.sh check-extension --wp-env` runs it end to end (part of `check-all --wp-env`).
- E2E: `pnpm e2e` (Playwright on wp-env, port 8910; specs in `dev/e2e/`). Local only, before a release; never on staging.

## Workflows folder (`apps/admin-page/src/routes/workflows/`)

- `api/` REST client + query hooks · `model/` graph and validation (no React code; step schemas come through `steps/`, whose registry also loads the step forms) · `steps/` one folder per step · `templates/` one folder per template · `pages/` one folder per page · `shared/` code used by two or more pages.
- Imports go one way: `pages/` → `shared/` → `api/`, `model/`, `steps/`, `templates/`. Pages never import from another page's folder; `shared/` never imports `pages/`; `steps/` and `templates/` never import `pages/` or `shared/`.
- Bottom layer: `model/` may import `steps/`; `templates/` may import `model/` and `steps/`; `steps/` and `api/` import nothing else from `workflows/`.
- New template = new folder in `templates/` + one line in `templates/index.ts`. Its `workflow` key groups versions ("Other versions" in the preview) and sets the "Most popular" rank (`workflowRank` in `templates/core/meta.ts`). Email messages are HTML: build them with `emailMessage()` (`templates/core/email.ts`). In development the registry throws if a template's graph has issues.
- New step = PHP class in `includes/Workflow/Triggers/`, `Conditions/` or `Actions/` implementing the step contract (`Core/Steps/`: label, icon from `StepIcons`, `fields()`, `outputs()`, `summary()`, `run()` → `StepResult`), listed in its module's `triggers()` / `conditions()` / `actions()` (`Modules/`) + TS folder in `steps/` (`defineStep({ id, kind })`: form and checks come from `fields()`; a custom form only for what fields can't draw) + one line in the TS registry. Another plugin's trigger or action needs no TS, an email action included (catalog `email` gives it Preview and Send test): the editor registers it from the catalog. Then `./run.sh meta-fixture`; the parity test checks ids, kinds, stored keys and messages. The graph JSON keeps the key `data.block` (stored data).
- A PHP step check that fields can't draw (its own `validate()`) shows as the step's issue after a save; a TS form in `steps/<kind>/<id>/` is only for checking it while typing.
- `steps/core/fields/` holds what step forms share: `StepForm` (drawn from catalog fields), `catalogSchema`, `widgets.tsx`, `RichTextEditor` for `html` fields (classic editor; PHP `AdminPage` calls `wp_enqueue_editor()`), `ConfigField`, `VariablePicker` / `InsertVariable`, and the per-step contexts `feedback`, `variableScope`, `emailTools`.
- `defineStep` extras beside the drawn form: `Footer` (under the form, gets the form's latest config) and `Actions` (panel header under the title).

## App shell and Settings (`apps/admin-page/src/routes/`)

- `layout/Layout.tsx` — sticky app header: `top` = `--wp-admin--admin-bar--height` (0 at ≤600px, where the admin bar scrolls away), z-40; sticky sub-bars under it (overview title bar) use z-30. Logo `assets/images/yaymatik-logo.png`. Light only: `main.tsx` sets `yay-light` on `<html>`. A page puts its header buttons into `#yay-header-actions` (`useHeaderSlot()` + `createPortal`): Settings' Save changes; the Create button of Overview, the editor and the template preview (`workflows/shared/create/CreateWorkflowButton`).
- `settings/` — `SettingsPage` (form, leave guard), sections `CartTrackingSection`, `EmailsSection`, `RunHistorySection`, `HealthPanel` (last card), `api/` (settings and health queries).
