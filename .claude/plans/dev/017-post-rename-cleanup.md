# Plan: Post-rename cleanup (plan 016 follow-ups)

## Goal
Close plan 016 follow-ups: prod script deps, JS translations, REST namespace constant, tool-owned scoper file, self-contained check-orders.

## Approach
- Script deps: one `ScriptName::ADMIN_DEPS` for dev and prod, covering every Vite external (`wp-date` was missing in prod). Dev also externalizes `@wordpress/date`, so dev loads what prod loads. A PHPUnit test parses the prod externals in `vite.config.ts` and checks each `@wordpress/x` has `wp-x` in the deps; finding none fails with a clear message.
- Translations: `wp_set_script_translations()` in `AdminPage::admin_enqueue_scripts` (covers dev and prod; the script is registered by then).
- `restBase` from `RestAPI::REST_NAMESPACE`.
- `scoper.inc.php` and `composer.json` are what `yaycommerce-prerelease` writes; commit that. Stop hook skips `scoper.inc.php` in phpcs (keeps `php -l`).
- check-orders: own temporary workflow (high-value shape: order_created → total ≥ 500 → then email + meta, otherwise note), two temporary orders (600, 100), real Dispatcher + Worker, real mail (Mailpit) captured via `wp_mail` filter. JSON keyed `high` / `low`, order numbers replaced by a placeholder. Cleanup through a shared helper in `check-run.php` that takes a filter: check-orders deletes only runs of its workflow or of its orders (and their AS jobs); check-runs keeps its current behavior (all runs after `$since`). Orders force-deleted (HPOS-safe). Prints leftover counts (workflow, orders, runs, jobs), all must be 0.

## Checkpoints
1. ✅ Code — Risk: 2 — deps, translations, restBase, guard test. Verify: pnpm build, test, lint, test:php, lint:php; admin page on pnpm dev (user checks).
2. ✅ Tooling — Risk: 1 — scoper/composer from the tool, stop hook exclusion, check-orders rewrite, docs line. Verify: `./run.sh release` then clean `git status`; `./run.sh check-orders` real actions, Mailpit mail, leftovers 0.

## Out of Scope
- Changing prerelease behavior (incl. its `composer install --no-dev`).
- check-runs changes beyond using the shared cleanup helper.
- `.pot` / JSON translation files.

## Follow-ups
- prerelease writes the latest caret range for php-scoper / safe, so `composer.json` can change again when new versions ship.

## Meta
- Target branch: jack/chore/post-rename-cleanup
- Base branch: develop
- Plan is private (not committed). Each commit needs its own "go".
