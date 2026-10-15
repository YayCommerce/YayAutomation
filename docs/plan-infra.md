# Plan: CLI Infra

Status: planned 2026-10-08. Split by when each piece starts paying off: a verify baseline before F0, the PHP test tools with F1. Reference: YayBookster (`tools/`, `run.sh`, `.github/workflows/ci-jobs.yml`).

## Goal

Cheap checks Claude and CI can run on their own: lint, unit tests, a GitHub workflow. Only what v1.0 needs.

## Today

| Area | State |
| --- | --- |
| TS lint | `pnpm lint` (eslint + prettier). 3 warnings |
| PHP lint | `phpcs.xml` exists, but phpcs is a global install on one machine, not in the project. 12 errors, 4 warnings |
| Tests | None (no vitest, no PHPUnit) |
| CI | None (no `.github/`) |
| Claude Stop hook | `pnpm build` + `php -l` (syntax only) |
| `run.sh` | Older copy of Bookster's. `claude-workflow` "Session here" always opens the plugin root (Bookster fixed it with `RUN_CWD`) |

## Phase 1: F0.0 Verify baseline (first checkpoint of the F0 branch)

**Status:** ✅ Done 2026-10-08 (commit `e225947`, PR #11).

ROADMAP-dev F0.3 checks F0 with `pnpm build` and phpcs. Without a clean phpcs and a few tests, nothing proves "no behavior change" except a manual run.

1. **PHP lint in the project.**
   - `dev/tools/lintphp/composer.json`: `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`, `phpcompatibility/phpcompatibility-wp`, `dealerdirect/phpcodesniffer-composer-installer` (as Bookster's `tools/lintphp`). `dev/` is in `.distignore`, so it never ships.
   - Root scripts `lint:php` / `lint:php:fix`, using the existing `phpcs.xml`.
   - Fix the 12 errors and 4 warnings, so F0 starts from zero.
2. **Vitest for TS.**
   - `vitest` in `apps/admin-page`, `test` script there, root `pnpm test` = `pnpm -r test`.
   - First tests pin today's behavior: `model/` (graph, validation), every template in the registry validates, `packages/utils`.
   - Setup file stubs `window.yayMatikMeta` with a fixture: step and data modules read it on import (`steps/core/pending.ts`).
3. **Claude Stop hook: `.claude/hooks/stop-check.sh`** for the whole team (taken from the closed `jack/chore/claude-token-optimization` branch).
   - Checks only what changed: `pnpm build` when `apps/`, `packages/` or package files change; `php -l` on changed PHP files.
   - Skips a change set that already passed (hash in `.git/`). Prints only on failure.
   - Also runs `pnpm test`, and phpcs on changed PHP files once `./run.sh setup` has installed the tools.
   - `settings.json` calls the script instead of today's two inline commands.
4. **CI: `.github/workflows/ci.yml`.** On push and pull request to `develop`: install → `pnpm lint` → `lint:php` → `pnpm test` → `pnpm build`.
   - No main `composer install`: it needs access to the private admin-shell repo. Only `dev/tools/*` composer installs.
   - No E2E, Slack or Turbo.
5. **Docs.** `.claude/docs/context-dev-convention.md` Verify: build + lint + test; a change in behavior adds or updates a test.

**Done when:** `pnpm lint`, `pnpm lint:php`, `pnpm test`, `pnpm build` all pass locally and in CI on `develop`.

## Any time

- `run.sh`: sync with Bookster's new version (aliases with args, fallback to root `package.json` scripts, `cmd:sub`, `ensure_deps`).
- `claude-workflow.sh`: the `RUN_CWD` fix for "Session here".

## Phase 2: with F1

1. **PHPUnit, plain (no WordPress loaded).** `dev/tools/phpunit/composer.json` + `phpunit.xml.dist` + `tests/bootstrap.php`, as Bookster. F1 brings the first framework-free PHP classes worth testing: template parser, Validator v2, Scope. CI gets a PHPUnit step.
2. **PHP ↔ TS parity test.** Since L2, PHP defines each step and the editor builds its checks from the catalog. A test that compares the catalog with what TS expects catches drift (Bookster: `packages/booking/test/parity.sync.test.ts`). Designed with F1.4.

## Not in v1.0 infra

| Item | Why |
| --- | --- |
| Husky + lint-staged | One developer; CI and the Stop hook catch the same things |
| `doctor.sh` | `/wsetup` covers it |
| E2E (Playwright + wp-env) | Belongs to M9.1 (end-to-end test of the 18 templates) |
| Turbo, Slack notifications | Small repo; Slack needs secrets |
