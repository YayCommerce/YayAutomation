# Plan: M9 release hardening (WordPress.org submission)

Status: written 2026-11-10; open decisions below go to Jack with /wdecide. One plan, one PR, two runs (one-pass mode).

## Goal
A free zip that passes the WordPress.org checklist, a light-only admin with a header Create button, a clean lifecycle (requirements, deactivation, uninstall), and every check, unit test and e2e journey run on both wp-env targets before release.

## Approach
- **Checklist first.** `.claude/docs/context-wporg-release-checklist.md` (S, G, B, Q, R, T, U; IDs as in ../YayBulkOrder) is the acceptance list. `/wp-review` builds `./run.sh release` → `release/yaymatik.zip`, unzips it to the scratchpad, runs each item's How there, and runs Plugin Check on the zip installed (not activated) as `yaymatik` on the wp-env site: `pnpm wp-env run cli wp plugin install wp-content/plugins/YayAutomation/release/yaymatik.zip`, `wp plugin check yaymatik`, then delete it. Pointer from `ops-release.md`, not CLAUDE.md (no growth).
- **Test targets.** `lib/wp-target.sh` gets `--wp-env-min` and runs wp-env's cli with `--env-cwd=wp-content/plugins/$PLUGIN_FOLDER`, so `eval-file dev/scripts/…` works in the container. The minimum site is its own config in `dev/wp-env-min/.wp-env.json` (port 8911, `WordPress/WordPress#6.9`, PHP 7.4, WooCommerce 11.0.0, plugin `../..`, mu-plugins `../wp/mu-plugins`), run as `pnpm --dir dev/wp-env-min exec wp-env …`. A separate folder is a separate wp-env instance; it doesn't depend on a `--config` flag. Every `check-*.sh` sources `wp-target.sh` and refuses `--staging`. `check-all.sh [target]` runs each check, keeps going on failure, prints one PASS/FAIL/SKIP table, exits non-zero on any FAIL.
- **Header.** Light only: `App.tsx` drops `ThemeProvider`; `main.tsx` puts `yay-light` on `<html>` once. `ThemeToggle.tsx` and the kit's `theme-provider.tsx` lose their last caller and go (listed for YayUiKits). `CreateWorkflowDialog` moves from `pages/list/` to `shared/create/`, since overview and editor may not import another page's folder. A new `shared/create/CreateWorkflowButton.tsx` owns the button, the dialog and the create mutation; "Browse templates" there navigates to `/workflows?templates=list`. `useHeaderSlot` moves from `SettingsPage.tsx` to `routes/layout/useHeaderSlot.ts` (Layout owns the slot). Overview and editor portal the button; the list portals nothing and keeps its in-page buttons.
- **Lifecycle.** `includes/Requirements.php`: plain PHP, required by `yaymatik.php` before `vendor/autoload.php`; a pure `missing( $wp, $php, $wc )` for PHPUnit. The WooCommerce version is only known after WooCommerce loads, so the `require` of `vendor/autoload.php` moves into `plugin_init()` (plugins_loaded), after the check. On failure: one notice on the Plugins screen only (`get_current_screen()->id === 'plugins'`), replacing today's "requires WooCommerce" notice; nothing else loads. `yaymatik.php` stays parseable on old PHP (no arrow functions or typed params in it). Deactivation: `register_deactivation_hook` → `Cleanup::unschedule()` (new): unschedules `yaymatik_cleanup`, `yaymatik_cart_sweep`, `yaymatik_inactive_sweep` and deletes `yaymatik_cleanup_checked_at` (what gates `ensure()`; it is an option, not a transient). Guarded by `function_exists( 'as_unschedule_all_actions' )`. `uninstall.php` requires `includes/Uninstaller.php` (no autoloader) and runs per site on multisite.
  - Always: delete Action Scheduler actions and their logs of group `yaymatik` by SQL (works when WooCommerce is already inactive), delete `_transient_yaymatik_%` / `_transient_timeout_yaymatik_%`.
  - With `YAYMATIK_REMOVE_ALL_DATA` true: drop the 7 Schema tables (unsubscribes included), delete `yaymatik_%` options, delete `_yaymatik_%` meta in postmeta and `wc_orders_meta` (when the table exists). Coupons stay; their `_yaymatik_*` meta goes.
  - The table list lives in `Uninstaller`; PHPUnit checks it against Schema's constants. `reset-data.sh` stays as it is (its list keeps unsubscribes and adds dev-era tables).
- **Release build.** Free zip: `rm -rf vendor-prefixed/src/License` and the license views (`license-card.php`, `license-activate-card.php`, `licenses-page.php`) before `composer dump-autoload`, plus the Pro license adapter (open 1). `sed -i ""` becomes `sed -i.bak … && rm *.bak` (BSD and GNU). `--rc` kept.
- **UI.** Kit strings wrapped in `__( …, 'yaymatik' )`. The AI box is dimmed like a disabled control. The runs empty state drops the docs line, and `docs` leaves `client_meta()`. Core logos become `data:image/svg+xml,…` from an inline SVG string in each module: 321 and 324 bytes, so no `file_get_contents`. Preflight: `main.css:8` imports it unscoped, so it reaches `#adminmenumain` and `#wpadminbar`. It can't be scoped to `.yay-ui` (that class is on `<body>`), and Radix portals render under `<body>`. So it is scoped to `:is(#yaymatik, body > :not(#wpwrap))`.
- **Docs** change in the same commit as the code they describe.

## Checkpoints

### Run 1

0. ✅ **Docs, API freeze, review skill** (5838e43) — Risk: 1
   - Files: `docs/ROADMAP-dev.md` (CT and its items ✅ PR #37; M9.1 light only; M9 items), `docs/ROADMAP-v1.0.md` (header status and date, phase statuses, release checklist as picked Oct 26 + Nov 9, criterion 6 light only, open question 7 answered, the "Nov 30" line, M5 row and Templates "Starts when" = Order status changed from pending / on-hold / failed to processing / completed, total ≥ 500; API freeze line), `docs/workflow-core-design.md` §8 (experimental hooks and actions until Extension API v1, v1.1; `YayMatik\Workflow\` internal), `@internal` one-liners on `Core/Steps/Step.php`, `Modules/Module.php`, `Core/Data/DataItem.php`, new `.claude/skills/wp-review/SKILL.md`, new `.claude/docs/context-wporg-release-checklist.md`, `.claude/docs/ops-release.md` (pointer).
   - D15: nothing left to drop. 031 removed the "delete reset-data.sh" line; grep finds it only in old plan files.
   - Tests: `pnpm lint:php`; `/wp-review` dry read of the skill.
   - Closes: none; sets up all IDs.
1. ✅ **Test targets** (856c522, fix ba62f7f) — Risk: 2
   - Files: `dev/scripts/lib/wp-target.sh`, all `dev/scripts/check-*.sh`, `check-late-module.sh` (temp file inside the checkout, under `dev/`, so the container sees it), `.wp-env.json` (WooCommerce 11.2.2), new `dev/wp-env-min/.wp-env.json`, new `dev/scripts/check-all.sh`, `dev/README.md`, `.gitignore` if wp-env writes there, MySQL per open 4.
   - Risk: a `dev/scripts/check-*.php` that uses PHP 8 syntax fails on PHP 7.4. Fix the script, unless it shows a plugin bug. A plugin bug is fixed in this CP and logged.
   - Tests: `./run.sh check-all`, `check-all --wp-env`, `check-all --wp-env-min` all PASS; `check-all --staging` refused.
   - Closes: R3 (minimum versions run), T3 in part.
2. ✅ **Header, light only** (be92a92, fix 326ef09) — Risk: 2
   - Files: `routes/layout/Layout.tsx`, new `routes/layout/useHeaderSlot.ts`, delete `routes/layout/ThemeToggle.tsx`, `App.tsx`, `main.tsx`, delete `components/ui/theme-provider.tsx`, move `pages/list/CreateWorkflowDialog.tsx` → `shared/create/`, new `shared/create/CreateWorkflowButton.tsx`, `pages/list/WorkflowListPage.tsx`, `pages/overview/WorkflowOverviewPage.tsx`, `pages/editor/WorkflowEditorPage.tsx`, `settings/SettingsPage.tsx`, `.claude/docs/context-dev-convention.md` (app shell line). Built to section #header of YayMatik Pages v3: outline, plus icon, "Create" below 640px.
   - Risk: the editor autosaves. Creating from the editor must not drop a pending save: the new workflow opens only after the current save settles.
   - Tests: Vitest: button on overview and editor, none on list, Save changes on settings; `<html>` has `yay-light` and never `yay-dark`, also with `localStorage['yay-ui-theme'] = 'dark'` and a dark `prefers-color-scheme`. e2e: Create workflow from the header on Overview and on the Editor → editor of a new workflow.
   - Closes: criterion 6 (light only).
3. ✅ **Lifecycle** (d6d4269, fix e79cfa3) — Risk: 3
   - Files: `yaymatik.php`, new `includes/Requirements.php`, `includes/Fallback.php` (Plugins screen only, G5), `includes/Workflow/Core/Runtime/Cleanup.php` (`unschedule()`), new `uninstall.php`, new `includes/Uninstaller.php`, `tests/Unit/RequirementsTest.php`, `tests/Unit/UninstallerTest.php`, `tests/Unit/Core/Runtime/CleanupTest.php` (or the existing file), new `dev/scripts/check-plugin-lifecycle.{sh,php}`.
   - check-plugin-lifecycle runs on wp-env targets only and refuses local and staging. The steps:
     - Force WP 6.8 in memory (`WP_CLI::add_wp_hook( 'muplugins_loaded', … )`): the notice is registered and `class_exists( 'YayMatik\YayMatik', false )` is false.
     - Deactivate: no pending recurring jobs, option gone. Reactivate + `action_scheduler_init`: all three back.
     - Uninstall, always as `wp plugin deactivate` + `wp plugin uninstall --skip-delete`. On wp-env the plugin folder is this git checkout, so the script refuses to run any uninstall without `--skip-delete`.
     - Without the constant: tables and options stay; group actions and transients gone. With `wp config set YAYMATIK_REMOVE_ALL_DATA true --raw` (trap removes it): tables, options and meta gone, a generated coupon stays.
     - Restore: activate, `reset-data --yes`, `seed`. Runs last in check-all.
   - Multisite: PHPUnit only (each site); not on wp-env.
   - Tests: PHPUnit for the requirements matrix, unschedule calls, uninstall lists vs Schema; check-plugin-lifecycle on both wp-env targets.
   - Closes: Q7, Q10, G5.
4. ✅ **Header fields, I18n, free build** (ebc598b, fix ab2c412) — Risk: 2
   - Files: `yaymatik.php` header (Requires at least 6.9, Requires PHP 7.4, Requires Plugins: woocommerce, WC requires at least 11.0, WC tested up to 11.2, License GPLv2 or later, License URI), `phpcs.xml` (6.9), `includes/I18n.php` and its call in `yaymatik.php` (open 2), `dev/scripts/release.sh`, `includes/YayMatikLicenseAdapter.php` / `YayMatikProLicenseAdapter.php` (open 1), `.claude/docs/ops-release.md`.
   - Tests:
     - Free zip: the G1 grep finds no License client, updater or Pro adapter; `vendor-prefixed/src/License` is missing; the admin page loads on `--wp-env-min` with the zip installed.
     - Pro zip: still has them.
     - `--rc` stamps both lines.
     - The `release.sh` sed lines run in a Debian container (GNU sed).
   - Closes: G1, B4, Q4, R2, R4.
5. ✅ **UI** (02cd472) — Risk: 1
   - Files:
     - Kit strings: `components/ui/pagination.tsx`, `dialog.tsx` (sr-only Close), `breadcrumb.tsx` (More), `command.tsx` defaults, the rest of the sweep.
     - AI box: `shared/create/CreateWorkflowDialog.tsx`.
     - Runs and docs: `pages/overview/WorkflowRuns.tsx` (D10 and D20b, D20c), `pages/overview/RunDetailSheet.tsx` (D20a), `includes/Engine/BEPages/AdminPage.php` and `types/localize.d.ts` (drop `docs`).
     - Logos: `includes/Workflow/Modules/{YayMatikModule,WoocommerceModule}.php`, `assets/logos/` deleted if nothing else reads it, `dev/scripts/meta-fixture.sh` (logo URL rewrite goes), `docs/workflow-core-design.md` §8 logo line, then `./run.sh meta-fixture`.
     - Preflight: `main.css`.
   - D8: tags-input isn't imported by the app, so it is skipped.
   - D9: there is no real input today (placeholder `<span>`s). The box, send button and chips get the kit's disabled look (`opacity-50`, `cursor-not-allowed`); the chips get `aria-disabled`; nothing in the box is focusable or clickable.
   - D20c reference: open 6.
   - Tests: Vitest for D9 (no focusable element in the box, chips `aria-disabled`, a click does nothing) and for the runs empty state (no link); the meta parity test; screenshots of the admin menu before and after the preflight scope, and of every dialog, popover, sheet and toast still styled.
   - Closes: Q3, Q5 (JS part).
6. ✅ **check-settings, other journeys** (f20f235, fix 16e6c54) — Risk: 2
   - Files: new `dev/scripts/check-settings.{sh,php}`, `dev/scripts/seed.php` (a "Classic checkout" page with `[woocommerce_checkout]`), `dev/e2e/specs/journeys.spec.ts` or new spec files, `dev/e2e/support/*`.
   - check-settings: GET/PUT, each range, all-or-nothing save, 400 per field, Health check checks and each fix. "Cart tracking off blocks Activate" is already in check-carts, so it is not added twice.
   - e2e: Settings save + Health check panel; an order through classic checkout; an unsubscribe link stops the next email. 031 rules apply.
   - Tests: check-settings on all three targets; `pnpm e2e` green three runs in a row.
   - Closes: criteria 2, 6 (classic checkout).
7. ✅ **readme, release gate** (4373e0f) — Risk: 1
   - Files: new `readme.txt`, `.claude/docs/ops-release.md` (gate, D19f order), `.distignore` if needed.
   - readme per D13: ≤ 5 tags, Stable tag 1.0.0, Tested up to 7.1, Source Code per open 3. Third-party libraries bundled in `main.js` (TanStack Query, Radix, motion, lucide, react-router …) are credited with name, version and URL.
   - Tests: readme validator (by hand, link in report), R2 versions equal.
   - Closes: R1, R2, R3, R5, R6, B1 (per open 3), B2, B3.

**End of run 1:** `check-all --wp-env`, `check-all --wp-env-min`, `pnpm e2e`, `/wp-review` on the zip. Report only.

### Run 3

10. ✅ **UI fixes from Jack's browser check** (dc31f27, fix 5cf3ab6) — Risk: 2
11. ✅ **Workflows list buttons, step reveal per axis** (5f164b3) — Risk: 1
   - `pages/list/WorkflowListPage.tsx`: the title-row Browse templates / Create workflow show only when `workflows?.length > 0`. Empty: the empty state's pair only. Loading or error: none.
   - `pages/editor/revealViewport.ts` (pure), called from `ViewKeeper.bringIntoView` in `WorkflowCanvas.tsx`: an axis where the step already fits keeps its offset; the other is centred in the area beside the panel. Zoom and the 300 ms pan stay.
   - Tests: `revealViewport.test.ts` (4: in view, panel only, below the fold only, both axes), `WorkflowListPage.test.tsx` (3). Lint 0 errors, Vitest 492, build OK. Browser at 1366×657 (1366×768 minus browser bars): a step under the panel moves sideways only (y 48 → 48); a step under the panel and below the fold is centred; empty list shows one pair.

12. ✅ **Release lint, translator comments, list title row, schedule** (e8a3cea) — Risk: 1
   - `dev/scripts/release.sh`: the phpcbf pass on the build folder is gone. With a path argument phpcs skips `phpcs.xml`'s `<file>` list, so it rewrote `vendor-prefixed` after `free-admin-shell.php` patched and verified it (2667 changed lines in 29 files). The script now runs `pnpm lint:php` on the source first and stops on an error, or when the tools are missing ("run ./run.sh setup").
   - Translator comments sit right before the `__()` / `_n()` call inside the expression, so terser keeps them when it rewrites the statement; 14 strings that lost theirs now carry one. Strings used with two comments get one generic comment ("%s needs a subject." / "needs a message." / "needs a field name.": step name; "%s no longer exists."; "%d selected"; "Sent to %s"). No msgid changed (990 before and after).
   - `WorkflowListPage.tsx`: the title row has `min-h-9`, so hiding the buttons doesn't shift the layout. Its test disposes the first router before the second render.
   - `schedule-v1.md`: the M9 plan is 032; the "Kept out of git" sentence is gone.
   - Tests: install, lint, lint:php, Vitest 492, PHPUnit 905, build. `./run.sh release`: no phpcbf output; make-pot prints only the known `GenerateCoupon.php:119` warning. The zip's `vendor-prefixed` equals the checkout's plus `free-admin-shell.php` (applied to a copy, `diff -r` clean).

### Run 2 (after Jack's checks: 8 workflows on staging, inbox check, screens of D1, D9, D20)

8a. ✅ **Build: free admin-shell patch, composer.json, adapter, sed line, boot, S9, jsx-runtime, exceptions** (ca35c23, fixes 423e552, e9c1575, d12c4f2) — Risk: 2
8b. ✅ **`%i` for table and column names** (3b6d48f, fix a1a6f11) — Risk: 3
8c. ✅ **Q12 / Q13: query limits and catalog visibility** (56b32f9, fix 592ec50) — Risk: 2
8d. ✅ **Q3 text rules scope; Q6 / Q11 clean-site run** (b856879) — Risk: 2

8. **Fixes** — Risk: 2 — from run 1's results and Jack's checks. Closes whatever is still open from T1, T2, S1–S13, Q1–Q13.
9. ✅ **Translation file, final docs, PR text** (7964845) — Risk: 1
   - Files: `package.json` (`makepot`: `wp i18n make-pot . languages/yaymatik.pot --include=yaymatik.php,includes,assets/dist --domain=yaymatik`; locations kept, see open 7), `dev/scripts/release.sh` (makepot after the build, as YayBulkOrder does), `languages/yaymatik.pot`, docs, `.claude/plans/dev/032-pr.md`.
   - Tests: the JS string count from `assets/dist` vs from `apps/admin-page/src` (make-pot on the source); report the missing ones.
   - Closes: Q5.

## Clean-site items

| ID | Target | How |
|---|---|---|
| Q7 | `--wp-env`, `--wp-env-min` | check-plugin-lifecycle; plus delete from the Plugins screen by hand on a clean site (U1) |
| Q11 | `--wp-env-min` after `wp-env clean all` | mapped checkout deactivated, zip installed, `WP_DEBUG` on, `wp plugin activate yaymatik` prints nothing, `debug.log` empty |
| T3 | both wp-env targets | the zip with only WooCommerce active: 6.9 / PHP 7.4 / WC 11.0 and 7.1 / PHP 8.1 / WC 11.2.2. Latest PHP (8.5) is staging's web PHP, covered by the 8 workflows |
| U1 | by hand (Jack) | upload the free zip in Plugins › Add New on a clean site, smoke test |

## Findings against the brief

**Conflicts (open decisions)**
- D7: `YayMatikLicenseAdapter` isn't a license client. It is the free menu adapter (`PluginMenuAdapter`), and `YayMatik.php:45` registers it, so the free zip can't drop it. `YayMatikProLicenseAdapter` has no caller. Open 1.
- D6: `I18n.php` calls `unload_textdomain( 'yaymatik' )`, then loads only from `languages/`, which doesn't exist. On WP ≥ 6.7 an unload stops just-in-time loading. So removing only `load_plugin_textdomain` still blocks WordPress.org language packs for PHP strings. Open 2.
- B1: `https://github.com/YayCommerce/YayMatik` and `…/YayAutomation` (this repo's origin) both return 404, so the repo is private. The repo also holds `includes/Pro`. Open 3.
- D14: staging is MySQL 8.0.46. wp-env runs `mariadb:lts`, and the local site runs MariaDB 11.2.3. Staging is MySQL 8, but D19a forbids check-* there. The MySQL-sensitive code is `RunStore.php:495` (`REGEXP`, erase) and `GET_LOCK`. Open 4.
- D19a: `check-orders` and `check-status-flow` are before/after diff tools that print JSON, not PASS/FAIL. `check-status-flow` defaults to local order ids 1693/1694. Open 5.
- D20c: the runs empty state already uses the same tile classes as the Template Library's "No templates found" (`TemplatesDialog.tsx:206`). The other style is the Health check circle (`HealthPanel.tsx:70`). Open 6.
- D16: YayBookster ships no JSON. It injects `wp.i18n.setLocaleData` inline from its `.mo` (`EnqueueLogic.php:152`) and makes the pot with `--no-location`. Copying that conflicts with D6 (`wp_set_script_translations`). The app ships one `main.js` (plus a string-free motion chunk), so WordPress.org's JSON by file hash loads. Open 7.

**Resolved here (simplest option)**
- D1: the dialog lives in `pages/list/`, and pages can't import another page's folder, so it moves to `shared/create/`.
- D4: `ensure()` is gated by the option `yaymatik_cleanup_checked_at`, not a transient; deactivation deletes that option.
- D5: YayMatik writes no user meta, so uninstall skips usermeta. Transients in an object cache can't be listed; they expire within an hour. reset-data's table list is inline PHP in bash, so `Uninstaller` owns the list and PHPUnit checks it.
- D8: tags-input is unused, so it is skipped. Pagination's icon buttons have no labels; that is listed for YayUiKits, not added.
- D12: the unscoped preflight is confirmed (`main.css:8`). The scope selector is in Approach.
- D19b: the tracking-off Activate check stays in check-carts only.
- `Fallback.php`'s duplicate-install notice shows on every screen (G5); it moves to the Plugins screen in CP3.
- check-plugin-lifecycle: `wp plugin uninstall` without `--skip-delete` would delete the git checkout on wp-env, so the script hard-refuses that.

**Risks, no change**
- `vendor-prefixed` uses the text domain `yaycommerce`, so T1 will likely report `TextDomainMismatch`. The fix belongs in admin-shell.
- The free menu still registers with admin-shell, whose `PagesRouter` adds a Licenses submenu when its registry isn't empty. CP4 checks on the zip that free shows no Licenses page.
- `develop` is at `5000114` ("added plan", after `bfaf1a4`); the branch starts there.
- The memory note "plans are private" is stale: plans are committed since `5000114`.

## Open decisions (answered Nov 10, see Decisions)
1. Free menu adapter: rename `YayMatikLicenseAdapter` → `YayMatikMenuAdapter` and keep it in free; `release.sh` drops `YayMatikProLicenseAdapter` from the free zip **(recommended)** · or move the Pro adapter to `includes/Pro/` (namespace `YayMatik\Pro\`).
2. I18n: delete `I18n.php` and its call; WordPress loads its packs just in time, and `wp_set_script_translations` stays **(recommended)** · or only remove `load_plugin_textdomain` (keeps the unload bug).
3. Source code (B1): make a public repo for the free plugin (no `includes/Pro`), readme links there **(recommended)** · or ship `apps/` + `packages/` source in the free zip · or make this repo public (Pro code exposed).
4. MySQL 8: a read-only probe on staging: `SELECT` of `RunStore`'s `REGEXP` pattern on sample strings, plus `GET_LOCK` / `RELEASE_LOCK`, no writes **(recommended)** · or a MySQL 8.4 docker site for check-* (more than one compose file).
5. Diff tools: `check-orders` and `check-status-flow` get the target option but stay out of check-all **(recommended)** · or turn them into PASS/FAIL checks that make their own orders.
6. Runs empty-state icon (D20c): reference = Health check circle (`size-11 rounded-full`, token tile) · or the Template Library "No templates found" tile. Your screenshot decides; no default.
7. Translations: pot with file locations, no shipped JSON or `.mo`, no inline locale data; WordPress.org builds the packs **(recommended)** · or copy YayBookster's inline `setLocaleData`.

## Out of Scope
- Template changes.
- Dark mode (the `.yay-dark` CSS stays in the kit).
- Multisite on wp-env.
- Linux runs of the whole `release.sh` (only its sed lines are checked).
- CI changes.
- A readme mention of the API freeze.
- Upstream YayUiKits and admin-shell fixes (listed only).
- Shipped translations.

## Decisions
Final (Jack, Nov 9–10): D1–D20 as in the brief, with the resolutions above. 031 decisions 1–10 stand.

Opens answered (Jack, Nov 10); these override the Approach and checkpoints where they differ:
1. Rename `YayMatikLicenseAdapter` → `YayMatikMenuAdapter`, kept in free; `release.sh` drops `YayMatikProLicenseAdapter` from the free zip (CP4).
2. Delete `I18n.php` and its call. `wp_set_script_translations( ScriptName::PAGE_ADMIN, 'yaymatik' )` stays, without the languages path argument (no JSON shipped) (CP4).
3. Source: public repo `YayCommerce/YayMatik`, free code only (no `includes/Pro`), made public at submission; its default branch holds the zip's code. readme Source Code: `pnpm install && pnpm build` rebuilds `assets/dist` from that repo; `vendor-prefixed/` ships prebuilt PHP (CP7). `/wp-review` B1 checks that link once the repo is public.
4. MySQL 8: a read-only probe on staging: RunStore's `REGEXP` pattern on sample strings, `GET_LOCK` / `RELEASE_LOCK`, and the collation `SELECT` that `MetaKeys::user_collides()` (`MetaKeys.php:46`) relies on. No writes. In CP1 as `dev/scripts/probe-mysql.sh`, which only ever targets `--staging` and runs only `SELECT`s.
5. `check-orders` and `check-status-flow` get the target option and stay out of check-all.
6. Runs empty-state icon: the HealthPanel look (`HealthPanel.tsx:70`: `size-11 rounded-full`), with a token that contrasts on the grey runs section (CP5).
7. Translations: a `.pot` with file locations; no shipped JSON, `.mo` or inline locale data; WordPress.org builds the packs (CP9).

Additions (Jack, Nov 10):
- CP3: register the deactivation hook at top level in `yaymatik.php`, with a callback that needs no autoloaded class: `function_exists( 'as_unschedule_all_actions' )`, then unschedule the three hooks and `delete_option( 'yaymatik_cleanup_checked_at' )` directly. Deactivation must work on a site where Requirements failed and `vendor/autoload.php` never loaded. That replaces `Cleanup::unschedule()`. Before `plugins_loaded`, only the `require` of `vendor/autoload.php` needs the autoloader (no CLI commands or file-load hooks use it; `Fallback.php` needs none), and it moves into `plugin_init()` after the check. check-plugin-lifecycle covers "deactivate while requirements fail": force WP 6.8, schedule the jobs first, deactivate, no recurring jobs left.
- CP5 preflight: scope with `:where( #yaymatik, body > :not(#wpwrap) )` inside `@layer base`, so specificity and cascade order stay as today and utilities still win.

Run 2 decisions (Jack, Nov 10; CP8 builds them):
- G1: do what YayBulkOrder does (approved on WordPress.org). First a read-only study: its admin-shell version, its release removals, the approved zip's license/updater contents, whether ours fatals with the same removals; then the exact change. An admin-shell change stops for Jack's answer.
- Q3: the `.yay-ui` text rules (`text-foreground`, `text-sm`) move from `body.yay-ui` to the preflight's `:where( #yaymatik, body > :not(#wpwrap) )` scope; `body` keeps only the background. Re-measure the admin menu and bar as in CP5.
- Delete `includes/YayMatikProLicenseAdapter.php`, its `.distignore` line and the `grep -vx` filter in `release.sh`.
- Drop the `YAYMATIK_IS_DEVELOPMENT` sed line in `release.sh`.
- `AdminShell::boot()` runs once: keep the call in `plugin_init`, after checking a second call has no side effect.

Run 2 answers (Jack, Nov 10; these replace the G1 study line above):
- G1, option b (no admin-shell repo change): the free build removes `vendor-prefixed/src/License` before `composer dump-autoload`, as YayBulkOrder does, then patches the scoped copy in the build folder only: a `class_exists` guard around every use of the License namespace in `src/Registry/LegacyBridge.php`, `src/Registry/AddonBridge.php` (incl. `new RestAPI`, `new License`) and `src/Menu/PluginSubmenu.php` (`new License`). Each patch is checked: a pattern not found exactly, or any file left in `vendor-prefixed` that still references the License namespace without a guard, fails the free build with file and line. Pro zip unchanged. Test: free zip on `--wp-env-min` alone and with the stand-in older Pro plugin with a saved key: wp-admin and WP-CLI load, no fatal.
- UnescapedDBParameter: every flagged query uses `%i` for table and column names, including `Uninstaller` and the dev scripts Plugin Check scans. No string-built table names left in `includes/`. Values stay `%d` / `%s`.
- T1 jsx-runtime: externalize `react/jsx-runtime` in `vite.config.ts`; `react-jsx-runtime` in `ScriptName::ADMIN_DEPS`; the motion chunk no longer inlines it.
- T1 ExceptionNotEscaped: `esc_html()` on the 4 messages.
- S9: the ABSPATH guard in `SingletonTrait.php`, `YayMatik.php`, `ScriptName.php`, `RegisterFacade.php`, `RegisterProd.php`; the 4 admin-shell files get it in the G1 build patch step, checked the same way.
- `composer.json` ships in the zip (out of `.distignore`).
- Q3, deleting `YayMatikProLicenseAdapter.php`, the `YAYMATIK_IS_DEVELOPMENT` sed line, one `AdminShell::boot()`: as decided above.
- Q12 + Q13: every product / order / customer query (product grids, review products, `site.new_products`, cart items, search REST, sweeps) has a server-side upper limit and respects catalog visibility (`exclude-from-catalog`; `exclude-from-search` for search). Fix what is missing, with tests.
- Q6 + Q11: on `--wp-env-min` after `wp-env clean all`: `WP_DEBUG` and `WP_DEBUG_LOG` on, mapped checkout deactivated, free zip installed, `wp plugin activate` prints nothing; every screen and one order through a workflow; `debug.log` has no YayMatik entries. Site restored after.
- U3 reply notes in `032-pr.md` under "WordPress.org review notes": S1 origin check instead of a nonce, the remaining hook-name warnings, `woocommerce_add_to_cart_validation`, the admin-shell build patch.
- Run 2 checkpoints: CP8a build · CP8b `%i` (Risk 3) · CP8c Q12/Q13 · CP8d Q3 + Q6/Q11 · CP9 translation file, final docs, PR text.

Run 3 decisions (Jack, Nov 10, after the browser check); CP10 builds them:
- D1 extended: the header "Create workflow" button also shows on the template preview page (`TemplatePreviewPage`), same button and dialog. Mock #header updated.
- Health check "Check again": the button shows its loading state at least 600 ms per click; after each scan a toast, "No problems found." or "Found N problem(s)." (`_n`); the summary line keeps its time.
- Cross-sell / upsell preview: diagnose the empty grid first (order #4584: no cross-sells, or CP8c's filters?); a CP8c filter that drops valid products is a bug, fixed with a test. Then, when the email uses `order.cross_sell_products` or `order.upsell_products`, Preview picks the newest paid order whose grid isn't empty; with none, the store's 4 newest visible products, as the sample-data preview does (plan 023 D8), and the header says "Sample products: no recent order has cross-sells". Send test uses the same choice. The seed gives its products cross-sells and upsells.

## Deviations
- CP1: `--wp-env-min` runs `../../node_modules/.bin/wp-env` from `dev/wp-env-min` (a shell function in `wp-target.sh`), not `pnpm --dir dev/wp-env-min exec wp-env`: pnpm refuses a folder without a `package.json`.
- CP1: `856c522` also took CP2's staged moves and deletes (`git mv` / `git rm`), so it doesn't build alone; the CP2 commit completes it. Not amended (commit rules).
- CP2: e2e ran with the installed Chrome (`channel: 'chrome'`, a scratchpad config): Playwright 1.64 ships no Chromium for macOS 12.
- CP4 (contradicts the release-build decision; open for Jack): the free zip keeps admin-shell's `vendor-prefixed/src/License` and license views. admin-shell 2.10.0's `LegacyBridge` runs in every copy and calls `License\LicenseState` (a fatal on a site where an older YayCommerce Pro plugin has a saved key), and its shared Licenses page includes the views and builds `License` / `RestAPI`. YayBulkOrder's lite drop works because its admin-shell 2.8.5 has no such call. G1 stays open until admin-shell has a free build mode or `class_exists` guards. Only `YayMatikProLicenseAdapter.php` leaves the free zip, through `.distignore`.
- CP5 (contradicts the decision's premise): the preflight is scoped with `:where( #yaymatik, body > :not(#wpwrap) )` but not put in `@layer base`. Today the whole app CSS, preflight included, is unlayered; in a layer it would lose to WordPress's unlayered admin CSS inside the app. Unlayered with `:where()`, specificity and order stay as before. Measured on wp-env (computed styles of the 361 elements of `#adminmenumain` and `#wpadminbar`, app sheet on vs off): before, box-sizing, border, width and height changed on up to 269 of them; after, none of those. What still differs is inherited font size, line height, colour and font family from `body.yay-ui` (`styles/preflight.css`), which predates this plan.
- CP5: D9 also drops the describe box's primary glow (a disabled control doesn't glow). The "Coming soon" chip stays at full opacity.
- CP5: the sheet's close button had no label; it gets the same translated sr-only "Close" as the dialog's.
- CP5: `ModuleRegistry::to_client()` passes `data` to `esc_url_raw()`, which otherwise drops the core logos' `data:` URIs.
- CP8a: 14 admin-shell files lacked the ABSPATH guard, not 4 (the run-1 listing was cut short); the patch step guards every shipped `vendor-prefixed` PHP file without one.
- CP8a: escaping the exception messages can show entities (e.g. `&#039;`) in run history, which React escapes already; only rare database-failure messages are affected. `RefResolver` passes an object, so its line has a targeted `phpcs:ignore`; `GoneEntity` escapes its own message.
- CP8a: without `src/License`, a free copy elected as the highest admin-shell renders the shared Licenses page but can't show or manage another plugin's license (same as YayBulkOrder's approved zip).
- CP8b: `Schema`'s `CREATE TABLE` statements keep string-built names: they go to `dbDelta()`, not to a `$wpdb` query method, and Plugin Check doesn't flag them. No `dev/` script was converted: `dev/` isn't in the zip, so Plugin Check never scans it; `check-carts` only had its SQL text matches updated for the backticks.
- CP8b: column names stay literal where they carry a table alias (`o.customer_id`): `%i` would quote the alias and the column as one name.
- CP8c: cart items aren't filtered by catalog visibility (they are the shopper's own cart); they get a 50-line cap with an "and N more items" line instead. `PersonSubjects` keeps `limit => -1`: it reads one person's order ids for the privacy export and erase, which must find them all. The admin search REST keeps hidden products: a merchant may target them in a condition.
- CP10 diagnosis: order #4584 (wp-env) has one line, Seed Tee, which had no cross-sells or upsells: the empty grid was no data, not CP8c. With the seed's cross-sells the same order shows 2 cross-sells and 1 upsell, so CP8c's filters keep valid products.
- CP10: "Found N problem(s)." counts checks with status `problem`, as the summary line does; a late-jobs info row alone gives "No problems found."
- CP10: Preview scans the newest 20 paid orders for one with a grid; with none it keeps the newest paid order's data and fills only the grid with the store's newest products.
- CP12 follow-up: `GenerateCoupon::summary()` returns `'%discount_type%' . wp_get_list_item_separator() . '%expiry_days%'` instead of a translatable string; make-pot read its `%d` / `%e` as unordered placeholders. The string leaves the .pot, and `languages/yaymatik.pot` is rebuilt (it also picks up CP12's comments). make-pot now prints no warnings.
- CP1: MySQL 8 probe ran on staging (8.0.46): REGEXP 8/8, GET_LOCK / RELEASE_LOCK, collation `utf8mb4_unicode_520_ci` folds case and accents, as `user_collides()` expects.

## Meta
- Target branch: jack/feature/032-m9-release
- Base branch: develop (5000114)
