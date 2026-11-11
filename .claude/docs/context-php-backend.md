# PHP Backend

`includes/` is namespace `YayMatik\` (Composer PSR-4). Workflow Core's design, folder map and step contract: `docs/workflow-core-design.md`.

## Entry points

- `yaymatik.php` → `includes/YayMatik.php`: wire new engine classes here.
- `yaymatik.php` loads `vendor/autoload.php` on `plugins_loaded`, only after `Requirements::met()` (WordPress, PHP, WooCommerce). Its deactivation hook and `uninstall.php` → `Uninstaller` load no autoloaded class. Uninstall deletes jobs and transients; tables, options and meta only with `YAYMATIK_REMOVE_ALL_DATA`.
- `includes/Engine/` is WordPress glue only (`BEPages/`, `FEPages/`, `API/`, `Register/`). REST controllers call Workflow Core and hold no workflow logic.
- `includes/Workflow/`: top level holds what modules register, `Core/` the shared code. Folders are named for the plural of the base class they hold.
- `includes/Pro/` is left out of the free zip. Free vs Pro is the folder: no tier checks inside steps.

## Gotchas

- Admin page hook suffix is `yaycommerce_page_yaymatik`. Its body gets `yay-ui yaymatik-page`, and `preflight.css` scopes WordPress overrides to `.yaymatik-page`.
- Never call `add_menu_page`: admin-shell owns the YayCommerce menu, Help and plugin row links.
- `./run.sh setup` builds `vendor-prefixed/` (`YayMatikScoped\`). The autoloader isn't authoritative, so new classes need no re-dump.
- `RegisterDev.php` is stripped from the release zip.
- Runtime is WooCommerce only and never throws into the host request.
- PHP and TS mirror each other: `Catalog`, `Messages` and `Validator` ↔ `validateWorkflow()`. After changing one, run `./run.sh meta-fixture`; the Vitest parity test catches drift.

## Rules

- Classes use `includes/Utils/SingletonTrait.php` and add hooks in the constructor.
- Every REST route needs `permission_callback` (see `RestAPI::can_manage`).
- Changing `yayMatikMeta` → update `apps/admin-page/src/types/localize.d.ts` and run `./run.sh meta-fixture`.
- Tests: `pnpm test:php` (plain PHPUnit, no WordPress) with fakes for the runtime seams. Real-site checks: `./run.sh check-*` (temporary data, removed after).
- Style: `phpcs.xml` (WPCS, 4 spaces, PHP 7.4+).
