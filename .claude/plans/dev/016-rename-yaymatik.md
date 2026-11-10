# Plan: 016 — Rename YayAutomation → YayMatik

## Goal
Rename the product YayAutomation → YayMatik everywhere, except the GitHub repo, git history and the clone folder.

## Rule
Every occurrence of the product name changes, except the GitHub repo (YayCommerce/YayAutomation: its URLs and the git remote), git history and old commit messages. The local clone folder keeps its name.

## Mapping (verbatim; apply case by case, never a blind global sed)
- YayAutomation → YayMatik (namespace, display name "YayMatik" / "YayMatik Pro", class names, @package tags, YayAutomationScoped → YayMatikScoped)
- yayAutomation → yayMatik (window.yayAutomationMeta → yayMatikMeta, yayAutomationApi, window.yayAutomation in docs)
- yayautomation → yaymatik (Plugins::YAYAUTOMATION → YAYMATIK, WorkflowPlugin type, PluginIcon tones, fixtures, tests)
- yay-automation → yaymatik (text domain incl. phpcs.xml, main file yaymatik.php, REST yaymatik/v1, npm @yaymatik/*, package.json names, composer names yaycommerce/yaymatik(-lintphp|-phpunit), admin page slug + hook suffix yaycommerce_page_yaymatik, mount div id/class, script handles, AS group, Logger source, EDGE_ARROW_ID, release zip + folder, Pro slug yaymatik-pro and its license option names)
- yay_automation → yaymatik (tables {prefix}yaymatik_*, db_version option, CPT yaymatik_wf, AS hooks, error codes, meta keys incl. _yaymatik_order_created, filter yaymatik_workflow_runs_docs_url, lock names, dev script functions/meta)
- YAY_AUTOMATION / YAYAUTOMATION → YAYMATIK (constants)

Keep: --yay-* CSS tokens and yay-ui-theme (generic Yay brand).

## Decided (verbatim)
Slug "yaymatik" everywhere. Plugin URI and Pro store link → https://yaycommerce.com/yaymatik/. scoper exclude-classes 'YayAutomationPluginAdapter' is admin-shell's {Slug}PluginAdapter → YayMatikPluginAdapter. Pro get_item_id() keeps its TODO.

## Approach
Apply the mapping one area at a time, reviewing each case. Rename files with `git mv`. Regenerate generated code and lockfiles with the tools, never by hand. Keep the old local data until CP5, then remove it with `./run.sh drop-legacy`. Fresh activation creates `wp_yaymatik_*`. No migration: the plugin hasn't shipped.

**Expected breakage between checkpoints:**
- Between CP2 and CP3 the admin page breaks: PHP sends `yayMatikMeta`, TS still reads `yayAutomationMeta`.
- Old pending Action Scheduler actions (old hooks, group `yay-automation`) fail until drop-legacy runs in CP5.

## Checkpoints
1. ✅ Inventory — Risk: 1 — no edits. Results (tracked files, case-insensitive 1,359 hits):
   - By variant: `yay-automation` 740, `YayAutomation` 496, `yay_automation` 70, `YAY_AUTOMATION` 41, `yayAutomation` 39, `yayautomation` 13, `YAYAUTOMATION` 9. No "Yay Automation" with a space.
   - By area: apps 513, includes 491, tests 210, dev 51, root 41, .claude 32, docs 16, packages 5.
   - File renames: `yay-automation.php` → `yaymatik.php`, `includes/YayAutomation.php`, `includes/YayAutomationLicenseAdapter.php`, `includes/YayAutomationProLicenseAdapter.php` → `YayMatik*`.
   - No tracked GitHub repo URLs; the exception has nothing to protect.
   - Generated + ignored: `vendor-prefixed/`, `vendor/`, `assets/`, root `composer.lock`. Tracked lockfiles: `pnpm-lock.yaml`, `dev/tools/{lintphp,phpunit}/composer.lock`.
   - Personal ignored files (`dev/.env.local`, `settings.local.json`, `CLAUDE.local.md`, `hooks.local`): no hits. Old private plans keep the old name (history).
   - Local site: `wp-config.php:115` has `YAY_AUTOMATION_IS_DEVELOPMENT`. Renaming the main file deactivates the plugin (`active_plugins` holds `YayAutomation/yay-automation.php`).
2. ✅ PHP — Risk: 3 — `git mv yay-automation.php yaymatik.php` and `includes/YayAutomation*.php`; apply the mapping in `includes/`, `tests/`, `phpcs.xml`, `composer.json` (name + PSR-4), `scoper.inc.php`, `dev/tools/*/composer.json`, then `composer update --lock -d dev/tools/<tool>` for both. Regenerate vendor-prefixed + autoloader with `./run.sh setup` (never hand-edit generated code). `pnpm test:php` + `pnpm lint:php`. Activate the renamed plugin with wp-cli; list the wp-config.php lines to change (YAY_AUTOMATION_* → YAYMATIK_*). Don't edit files outside the repo.
3. ✅ TS — Risk: 2 — package names and imports, window meta + `localize.d.ts`, error codes, types, PluginIcon tones, fixtures, tests; `pnpm install` (refresh pnpm-lock); `./run.sh meta-fixture`. `pnpm build` + `test` + `lint`.
4. ✅ Scripts, docs — Risk: 1 — `run.sh`, `dev/scripts` (incl. `release.sh` PLUGIN_SLUG and its sed line), `.distignore`, README, `dev/README`, `docs/*`, `.claude/CLAUDE.md`, `.claude/docs`, `.claude/skills`. CI: `ci.yml` has no hits, just confirm. Delete stale `release/yay-automation.zip`.
   - Done: 23 files. `run.sh`, `setup.sh`, `ci.yml` had no hits. `./run.sh release` → `release/yaymatik.zip` (`yaymatik/yaymatik.php`, no IS_DEVELOPMENT, no RegisterDev.php). Release rewrites `scoper.inc.php` from the admin-shell template and bumps php-scoper in `composer.json`; reverted (not part of 016).
5. ✅ Verify — Risk: 3 — add `dev/scripts/drop-legacy.sh` + `./run.sh drop-legacy`: drops `wp_yay_automation_*` tables, old db_version option, `yay_automation_wf` posts + postmeta, AS actions (old hooks + group `yay-automation`), order meta `_yay_automation_*` (HPOS + postmeta). Run it. `git grep -i -E 'yay.?automation'` hits only drop-legacy.sh (list any other). Fresh activate creates `wp_yaymatik_*` tables. `./run.sh check-runs`, `check-status-flow`, `check-storage` pass. `pnpm dev` serves the admin page at `page=yaymatik` (user checks in browser).
   - Done: drop-legacy removed 4 tables, 1 option, 3 posts + 18 postmeta, 29 AS actions + 79 logs + group, 1 order postmeta, 3 HPOS order meta; second run removes 0. The group also held old hook `yay_automation_run_workflow` (12 actions). Fresh install: tables + db_version 2 come back on the first load after activation (install runs on load, not the activation hook). check-runs, check-status-flow, check-storage pass; check-orders default workflow 1695 doesn't exist, ran with 1785 (condition not met by orders 1693/1694, no output). git grep hits only drop-legacy.sh + dev/README. build, test, lint (3 old warnings), test:php, lint:php pass.
   - User browser check on `page=yaymatik` passed: list, editor, template → activate → test order → `yaymatik_run` completed, run shown, email caught. Stale workflow 1785 deleted.

Each checkpoint: mark ✅ here, report, stop.

## Out of Scope
- GitHub repo name, git remote, git history, clone folder name.
- `--yay-*` CSS tokens, `yay-ui-theme`.
- Files outside the repo (`wp-config.php`, any separate Pro plugin repo): list lines for the user.
- Old private plans, Claude memory files.

## Follow-ups (separate branch, not 016)
- `check-orders` defaults to missing workflow 1695 and orders 1693/1694, whose totals miss the template's condition. Make it create its own workflow and order, like `check-runs`.
- `RegisterProd` script deps miss `wp-date` (prod bundle reads global `wp.date`) — fix before first release.
- `wp_set_script_translations()` never called for the admin script; JS strings stay untranslated.
- `AdminPage` hardcodes `restBase` instead of `RestAPI::REST_NAMESPACE`.
- `yaycommerce-prerelease` rewrites `scoper.inc.php` from the admin-shell template and bumps php-scoper to `^0.18.20` on every release. Treat `scoper.inc.php` as tool-owned: exclude it from the stop hook and phpcs, keep the template format, commit the `^0.18.20` bump.

## Meta
- Target branch: jack/chore/rename-yaymatik
- Base branch: develop
- Plan is private (not committed). No commits until the user says.
