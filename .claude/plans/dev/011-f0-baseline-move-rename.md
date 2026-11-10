# Plan: F0 — Verify baseline + move and rename

## Goal
On one branch: first add cheap checks (phpcs, Vitest, Stop hook, CI) on today's code, then move Workflow Core to `includes/Workflow/` and rename Blocks → Steps with the same checks passing unchanged.

## Approach
ROADMAP-dev F0.0 + F0.1–F0.3, design §1, plan-infra Phase 1. Two checkpoints, two commits, in order: CP1 sets the baseline on the old layout, CP2 is the mechanical move. No behavior change. Data that is stored or sent over REST keeps its names (`data.block` in graph JSON, issue code `no_block`). F0 runs with no other branch open.

## Checkpoints

1. Verify baseline (F0.0) — Risk: 2 — lint, tests, hook and CI on today's code
   - **PHP lint**
     - `dev/tools/lintphp/composer.json`: `squizlabs/php_codesniffer` ^3.8, `wp-coding-standards/wpcs` ^3.0, `phpcompatibility/phpcompatibility-wp` ^2.1, `dealerdirect/phpcodesniffer-composer-installer` ^0.7 (allow-plugins). `dev/` is in `.distignore`.
     - `phpcs.xml`: `<file>src/</file>` → `<file>includes/</file>` (stale path).
     - Root scripts: `lint:php` (`dev/tools/lintphp/vendor/bin/phpcs`), `lint:php:fix` (phpcbf). `dev/scripts/setup.sh` also runs `composer install` in `dev/tools/lintphp`.
     - Fix every current finding (12 errors, 4 warnings: phpcbf for 9, by hand for the rest). Output-escaping finding: escape, or `phpcs:ignore` with the reason if already safe. No behavior change.
   - **Meta fixture**
     - Extract `AdminPage::client_meta(): array` from `admin_enqueue_scripts()` (same array, no change in output).
     - `dev/scripts/meta-fixture.sh`: `wp eval` → `client_meta()` as JSON → `apps/admin-page/src/test/fixtures/meta.json`. Line 2 comment = description (shows in `./run.sh`).
   - **Vitest**
     - `vitest` + `happy-dom` devDeps in `apps/admin-page`; `test: vitest run`; root `test: pnpm -r test`.
     - Config reuses `vite.config.ts` aliases; setup file sets `window.yayAutomationMeta` from the fixture (modules read it on import, e.g. `blocks/core/pending.ts`).
     - First tests, pinning today's behavior: `model/graph.ts`, `model/structure.ts`, `model/validation.ts` (issue codes per broken graph), and every template in `templates/` validates with no issues.
   - **Stop hook**
     - `.claude/hooks/stop-check.sh` from commit `0148eb2` (build + `php -l` on changed files, skip a change set that passed). Add: `pnpm test` when `apps/` or `packages/` change; `lint:php` on changed PHP files when the lintphp vendor exists.
     - `.claude/settings.json` Stop → the script. Commit with `ALLOW_SETTINGS_COMMIT=1`.
   - **CI** `.github/workflows/ci.yml`: push + pull request to `develop`; Node 22, pnpm 10, PHP 8.1. `pnpm install` → `pnpm lint` → composer install in `dev/tools/lintphp` → `pnpm lint:php` → `pnpm test` → `pnpm build`. No main `composer install` (private admin-shell).
   - **Docs**: `.claude/docs/context-dev-convention.md` Verify (build + lint + lint:php + test; behavior change → add or update a test; the hook runs them). ROADMAP-dev F0.0 "its own PR before F0.1" → "first checkpoint of the F0 branch"; same in `docs/plan-infra.md`.
   - Test:
     - `pnpm lint`, `pnpm lint:php` (0 errors, 0 warnings), `pnpm test`, `pnpm build` pass.
     - Admin page loads; editor, list and template preview work as before (`/wcheck-lite`).
     - Stop hook blocks on a deliberately failing test, then passes.

2. Move and rename (F0.1–F0.3) — Risk: 2 — Workflow Core in `includes/Workflow/` (design §1), Blocks → Steps in PHP and TS
   - **PHP**, with `git mv`, namespaces `YayAutomation\Engine\…` → `YayAutomation\Workflow\…`:

     | From `includes/Engine/` | To `includes/Workflow/` |
     |---|---|
     | `Workflows/WorkflowRepository`, `VersionStore`, `WorkflowPostType` | `Storage/` |
     | `Workflows/WorkflowValidator` | `Definition/` |
     | `Workflows/Runner` | `Runtime/` |
     | `Blocks/Core/Block`, `BlockRegistry` | `Steps/Core/Step`, `StepRegistry` |
     | `Blocks/Core/{Trigger,Action,Condition}`, `Blocks/{Actions,Conditions,Triggers}/*` | `Steps/…` (same names) |
     | `Data/*` | `Data/*` |
     | `Plugins.php` | `Extend/Plugins.php` |

     - `Engine/` keeps `API/`, `BEPages/`, `Register/`. Update every `use`, `YayAutomation.php` and the bootstrap.
     - Rename identifiers that name the concept (class names, `$block` variables, comments). Never change string keys: `'block'` in graphs, `no_block`, meta keys, REST fields.
     - Localize key `automation.blocks` → `automation.steps` (PHP + `types/localize.d.ts`), then rerun `dev/scripts/meta-fixture.sh`.
     - `composer dump-autoload`.
   - **TS**
     - `git mv apps/admin-page/src/routes/workflows/blocks steps`; fix imports.
     - Rename: `defineBlock` → `defineStep`, `BlockId` → `StepId` (`packages/types`), `blockOf` → `stepOf`, `Block` → `Step`, `BlockFormProps` → `StepFormProps`, `BlockMeta` → `StepMeta`, `BlockSpec` → `StepSpec`, `BlockPicker`/`BlockRow` → `StepPicker`/`StepRow`, `BlockRegistry` → `StepRegistry`, `pendingBlocks` → `pendingSteps`, `triggerBlock`/`conditionBlock`/`waitBlock`/`criteriaBlock` → `…Step`, `withBlock` → `withStep`.
     - **Not renamed** (they mean "blocking"): `useBlocker`, `deleteBlocked`, `draftBlocksTurnOn`, `shouldBlock`. Graph field `block` stays.
   - **Docs**: `.claude/docs/context-php-backend.md`, `.claude/docs/context-dev-convention.md` (workflows folder section), comments in `packages/types`. Design §0 keeps the old paths (it describes v0.1).
   - Test:
     - `pnpm lint`, `pnpm lint:php`, `pnpm test` (same tests, same results as CP1), `pnpm build`.
     - `grep` finds no `Engine\(Workflows|Blocks|Data)`, `Engine\Plugins` or `workflows/blocks/` left.
     - `wp eval` loads `StepRegistry` and the localize meta has `automation.steps`.
     - Manual: both triggers (Order created, Order status changed) and all 4 actions run on a real order (note, status, custom field, staff email in the YaySMTP log).
     - Admin: list, editor (pickers, forms, Insert/Add variable), template preview, overview open with no console errors (`/wcheck-lite`).

## Out of Scope
- Behavior, wording or UI changes (identity comes after F0).
- Renaming stored or REST data (`data.block`, `no_block`).
- PHPUnit and the parity test (F1.0, F1.4). Husky, doctor, E2E.
- Storage changes (CPT stays until F2).

## Meta
- Target branch: jack/refactor/move-rename
- Base branch: develop
