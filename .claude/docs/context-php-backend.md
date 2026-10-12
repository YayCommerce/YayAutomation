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
  - `Data/` — data items (order, customer, site, order_item) with typed fields (`Type`), condition `Operators`, `Scope` (what a run reads, by path: `trigger.*` / `order.*`, `steps.<id>.*`, `site.*`), `Template` (merge tags `{{ path | filter: arg }}`, the one renderer; `Filters` registry). The admin app reads the data catalog from `yayAutomationMeta.automation`.
  - `Steps/` — one class per step type. Contract (`Core/Step.php`): `id kind label description plugin module icon`, `fields()` (`Core/Field.php`), `sanitize()` (never fails; by default each field's), `validate()` (field rules + extra checks; an error names its `field`), `outputs()` (`key => [ label, type (Data\Type), options? ]`), `summary()` (`%field%` placeholders), `run( Scope, config ): StepResult` (`ok( outputs ) | skipped( reason ) | failed( error ) | wait( until )`), `on_error()` (`stop` by default). Triggers keep `provides()`, `listen()`, `claim()`; actions keep `requires()` / `uses()`. `icon()` is a name from `Core/StepIcons.php` (lucide), never SVG or a URL.
  - `Steps/Core/Field.php` — widgets: text, textarea, emails, select, multiselect, toggle, number, number_unit, notice, rules (core only); modifiers `required( '%s needs …' )` (filled with the step label), `variables`, `placeholder`, `help`, `show_if`, `default`, `min` / `max`.
  - `Extend/Modules.php` — manifest: module → tier (`lite` / `pro`), on/off, its steps. `Steps/Core/StepRegistry.php` builds from it; an off module's steps are unknown everywhere. No tier checks inside steps.
  - `Extend/Plugins.php` — plugins that provide steps (`woocommerce`, `yayautomation`), each with an own-drawn logo SVG (`currentColor`), cleaned with `wp_kses` (`Plugins::clean()`).
  - `Definition/Catalog.php` — `yayAutomationMeta.automation`: plugins, steps (contract keys in camelCase, fields, outputs), data, operators, always, messages. Keys are only added. Change → `./run.sh meta-fixture`; the Vitest parity test compares TS with it.
  - `Definition/GraphSchema.php` — graph shape on save (ids, kinds, one trigger, one parent per step, one child per handle) and its normalized form with `schemaVersion` (missing reads as 1; newer → 400). `same()` compares two graphs.
  - `Definition/Validator.php` — REST input → storable parts, and issues that block Activate / Update: workflow-level, then per step (settings, merge tags against what the step can reach: trigger data, outputs of the steps above, always items; then data it needs). Issues carry `code`, `message`, `field`, `nodeId`. `issues()` → `{ live, draft }` for REST responses. Mirrored by TS `validateWorkflow()`.
  - `Definition/Messages.php` — messages PHP and TS both show; TS copy in `apps/admin-page/src/routes/workflows/model/messages.ts`, kept equal by the parity test.
  - `Storage/` — `Schema` (tables, `dbDelta`), `WorkflowRepository` (CPT + post meta), `VersionStore`, `RunStore`, `ClaimStore`, `WorkflowPostType`.
  - `Runtime/Runner.php` — queues matching workflows through Action Scheduler at shutdown and runs them: one Scope per run, each step's outputs into it, conditions follow `then` on yes. A failed or skipped step is logged and the run goes on; a step that throws only fails itself. A workflow never re-runs in its own causal chain.

## Admin shell

- `./run.sh setup` (`composer install` + `./vendor/bin/yaycommerce-update`; `pnpm dev` runs it when `vendor/autoload.php` is missing) → `vendor-prefixed/` (namespace `YayAutomationScoped\`). Both `vendor/` and `vendor-prefixed/` are gitignored.
- Do not call `add_menu_page`. AdminShell owns the YayCommerce menu, Help, Recommended Plugins, and the plugin action/row links.

## Rules

- Classes use `includes/Utils/SingletonTrait.php` and add hooks in the constructor.
- `vendor/` is dumped classmap-authoritative: after adding a PHP class, run `composer dump-autoload`.
- Every REST route needs `permission_callback` (see `RestAPI::can_manage`).
- Changing `yayAutomationMeta` → update `apps/admin-page/src/types/localize.d.ts` and run `./run.sh meta-fixture`.
- PHP tests: `pnpm test:php` (plain PHPUnit, no WordPress; `tests/`). Real-site checks: `./run.sh check-storage`, `check-orders`, `check-status-flow`.
- Style: `phpcs.xml` (WPCS, 4 spaces, PHP 7.4+).
