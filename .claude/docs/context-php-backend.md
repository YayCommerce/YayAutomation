# PHP Backend

`includes/` maps to namespace `YayAutomation\` via Composer PSR-4 (`vendor/autoload.php`), which also loads the scoped admin-shell.

## Key Paths

- `yay-automation.php` — constants, Composer autoload, `plugins_loaded` boot
- `includes/YayAutomation.php` — entry; wire new engine classes here; `is_prod()`
- `includes/Engine/BEPages/AdminPage.php` — slug, capability, `yayAutomationMeta`, mount div. Hook suffix: `yaycommerce_page_yay-automation`
- `includes/YayAutomationLicenseAdapter.php` — submenu under YayCommerce via `yaycommerce/admin-shell` (Lite). Registered in `YayAutomation::register_admin_menu()`
- `includes/YayAutomationProLicenseAdapter.php` — license adapter, not registered (`TODO: EDD setup`)
- `includes/Engine/API/RestAPI.php` — routes under `yay-automation/v1`
- `includes/Engine/Register/` — script loading; `RegisterDev.php` is stripped from the release zip
- `includes/Engine/` — WP/WC glue only: `BEPages/`, `API/`, `Register/`. REST controllers call Workflow Core and hold no workflow logic.
- `includes/Workflow/` — Workflow Core, namespace `YayAutomation\Workflow\` (design §1):
  - `Data/` — data items (order, customer, site): typed fields, condition operators, `{{ item.field }}` merge tags. The admin app reads them from `yayAutomationMeta.automation`.
  - `Steps/` — one class per step type, listed in `Core/StepRegistry.php`. Triggers hook their own events (`listen()`) and declare `provides`; actions declare `plugin`, `category`, `requires`. Each mirrors a TS folder in `apps/admin-page/src/routes/workflows/steps/`.
  - `Storage/` — `WorkflowRepository` (CPT + post meta), `VersionStore` (versions table), `WorkflowPostType`.
  - `Definition/WorkflowValidator.php` — graph shape on save, issues that block activating.
  - `Runtime/Runner.php` — queues matching workflows through Action Scheduler at shutdown and runs them; a workflow never re-runs in its own causal chain.
  - `Extend/Plugins.php` — plugin groups for the editor's pickers.

## Admin shell

- `./run.sh setup` (`composer install` + `./vendor/bin/yaycommerce-update`; `pnpm dev` runs it when `vendor/autoload.php` is missing) → `vendor-prefixed/` (namespace `YayAutomationScoped\`). Both `vendor/` and `vendor-prefixed/` are gitignored.
- Do not call `add_menu_page`. AdminShell owns the YayCommerce menu, Help, Recommended Plugins, and the plugin action/row links.

## Rules

- Classes use `includes/Utils/SingletonTrait.php` and add hooks in the constructor.
- `vendor/` is dumped classmap-authoritative: after adding a PHP class, run `composer dump-autoload`.
- Every REST route needs `permission_callback` (see `RestAPI::can_manage`).
- Changing `yayAutomationMeta` → update `apps/admin-page/src/types/localize.d.ts`.
- Style: `phpcs.xml` (WPCS, 4 spaces, PHP 7.4+).
