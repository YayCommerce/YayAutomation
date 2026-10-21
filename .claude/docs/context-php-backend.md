# PHP Backend

`includes/` maps to namespace `YayMatik\` via Composer PSR-4 (`vendor/autoload.php`), which also loads the scoped admin-shell.

## Key Paths

- `yaymatik.php` — constants, Composer autoload, `plugins_loaded` boot
- `includes/YayMatik.php` — entry; wire new engine classes here; `is_prod()`
- `includes/Engine/BEPages/AdminPage.php` — slug, capability, `yayMatikMeta`, mount div. Hook suffix: `yaycommerce_page_yaymatik`
- `includes/YayMatikLicenseAdapter.php` — submenu under YayCommerce via `yaycommerce/admin-shell` (Lite). Registered in `YayMatik::register_admin_menu()`
- `includes/YayMatikProLicenseAdapter.php` — license adapter, not registered (`TODO: EDD setup`)
- `includes/Engine/API/RestAPI.php` — routes under `yaymatik/v1`; `POST /workflows/{id}/preview` and `/send-test` (`manage_woocommerce`, body `{ nodeId, config, graph? }`) → `Workflow/Core/Email/EmailPreview.php`
- `includes/Engine/FEPages/UnsubscribePage.php` — store-front unsubscribe page (own HTML, not the theme)
- `includes/Engine/Register/` — script loading; `RegisterDev.php` is stripped from the release zip
- `includes/Engine/` — WP/WC glue only: `BEPages/`, `FEPages/`, `API/`, `Register/`. REST controllers call Workflow Core and hold no workflow logic.
- `includes/Workflow/` — Workflow Core, namespace `YayMatik\Workflow\` (design §1). Layout rule: the top level holds what modules register, `Core/` holds the shared code every module uses (`includes/Engine/` = WordPress glue: REST, admin pages, scripts), and each folder is named for the plural of the base class it holds; namespaces follow folders.
  - `ModuleRegistry.php` + `Modules/` — one module per plugin (`Module`: id, label, description, `logo()` = URL of a one-colour SVG the plugin ships, `active()`, `triggers()` / `conditions()` / `actions()` / `data_items()` as class names). Core: `YayMatikModule` (Criteria, site data), `WoocommerceModule` (order triggers, the six actions, order / customer / order_item data); logos in `assets/logos/`. `classes()` = the Pro modules when `includes/Pro/` ships, else the free ones, then the filter `yaymatik_modules` (class names; anything not a `Module` subclass is ignored). Another plugin adds the filter before `init`: the registry is first read on `init`, when the dispatcher registers trigger listeners (`./run.sh check-late-module`). A taken id (module, step, data item) keeps its first owner. The catalog sends each step's module id as `plugin`. Free vs Pro is the folder; an unfinished step isn't registered. No tier checks inside steps.
  - `Triggers/` (`OrderTrigger`, `OrderCreated`, `OrderStatusChanged`), `Conditions/` (`Criteria`; condition fields are data item fields), `Actions/` (`SendEmail` with the WC template, unsubscribe footer and List-Unsubscribe headers; `SendInternalEmail`, `ChangeOrderStatus`, `AddOrderNote`, `UpdateOrderMeta`, `GenerateCoupon` with outputs `code`, `amount`, `expires_at`, `box`).
  - `DataItems/` — order, customer, site, order_item (`standalone()` false: reached only through `order.items`).
  - `Core/Steps/` — the step contract only. `Step`: `id kind label description icon`, `fields()` (`Field`), `sanitize()` (never fails; by default each field's), `validate()` (field rules + extra checks; an error names its `field`), `outputs()` (`key => [ label, type (Data\Type), options? ]`), `summary()` (`%field%` placeholders), `run( Scope, config ): StepResult` (`ok( outputs ) | skipped( reason ) | failed( error ) | wait( until )`), `on_error()` (`stop` by default). `Trigger` adds `provides()`, `listen()`, `claim()`; `Action` adds `requires()` / `uses()`. `icon()` is a name from `StepIcons` (lucide), never SVG or a URL. `StepRegistry` reads `ModuleRegistry`.
  - `Core/Steps/Field.php` — widgets: text, textarea, html (`wp_kses_post`), emails, select, multiselect, toggle, number, number_unit, notice, rules (core only); modifiers `required( '%s needs …' )` (filled with the step label), `variables`, `placeholder`, `help`, `show_if`, `default`, `min` / `max`.
  - `Core/Data/` — `DataItem` (typed fields, `Type`), `DataRegistry` (items = standalone module data items; entities = all), condition `Operators`, `Scope` (what a run reads, by path: `trigger.*` / `order.*`, `steps.<id>.*`, `site.*`), `Template` (merge tags `{{ path | filter: arg }}`, the one renderer; `Filters` registry), `Ref` / `RefResolver`, `SampleValues`. The admin app reads the data catalog from `yayMatikMeta.automation`.
  - `Core/Email/` — `EmailBody` (renders an `html` message via `Template::render_html()`), `UnsubscribeLink` (HMAC with option `yaymatik_unsub_secret`), `EmailPreview` (Preview / Send test: render only; data from the latest paid order or `SampleValues`, steps above give `sample_outputs()`).
  - `Core/Coupon/` — `CouponWriter` (seam) / `WcCouponWriter` (meta `_yaymatik_generated`, `_yaymatik_run_id`, `_yaymatik_node_id`), `FreeShipping::coupon_mode()` → `yayMatikMeta.wc.freeShippingCoupon`.
  - `Core/Definition/Catalog.php` — `yayMatikMeta.automation`: plugins (logo URLs), steps (contract keys in camelCase, fields, outputs), data, operators, always, messages. Change → `./run.sh meta-fixture`; the Vitest parity test compares TS with it.
  - `Core/Definition/GraphSchema.php` — graph shape on save (ids, kinds, one trigger, one parent per step, one child per handle) and its normalized form with `schemaVersion` (missing reads as 1; newer → 400). `same()` compares two graphs.
  - `Core/Definition/Validator.php` — REST input → storable parts, and issues that block Activate / Update (workflow, then per step: settings, merge tags the step can't reach, data it needs). Issues carry `code`, `message`, `field`, `nodeId`. `issues()` → `{ live, draft }` for REST responses. Mirrored by TS `validateWorkflow()`.
  - `Core/Definition/Messages.php` — messages PHP and TS both show; TS copy in `apps/admin-page/src/routes/workflows/model/messages.ts`, kept equal by the parity test.
  - `Core/Storage/` — `Schema` (tables, `dbDelta`), `UnsubscribeStore`, `WorkflowRepository` (CPT + post meta; `live_version_id()`), `VersionStore`, `RunStore` (runs; `claim()` is the row lock), `RunStepStore` (one row per step a run ran), `ClaimStore`, `WorkflowPostType`.
  - `Core/Runtime/` — wired by `Runtime::boot()` (WooCommerce only; trigger listeners on `init`). Never throws into the host request.
    - `Dispatcher` — triggers report events while a request runs; at shutdown: active workflows for the trigger, `claim()`, `validate()` + `matches()`, then per match a queued `runs` row (live version pinned, `Trigger::refs()` as scope, parent run) and one Action Scheduler job (`yaymatik_run`, args `[ run_id ]`); inline without Action Scheduler. Loop guard: workflow already in the parent chain, depth > 5. Turned off (logged) at ≥ 100 runs started by other runs, or ≥ 1000 runs in all, within 60 s.
    - `Worker` — the job: claims the run, cancels it (workflow off or deleted) or skips it (trigger entity gone) before the first step, then runs an `Executor` slice. Action Scheduler failure hooks queue a recovery job (`yaymatik_recover_run`) that may take a `running` run.
    - `Executor` — walks the pinned graph from the cursor (`position.queue`); a `run_steps` row before and after each step; declared outputs into `steps.<id>` (output display texts via `Scope::set_output_display()`); `run.id` / `run.step` set before each step (internal, not user variables); stop / continue policy; wait; ~20 s / 80% memory slices. Never reruns a finished step; a step left `running` fails as "Interrupted.".
    - Seams for PHPUnit fakes: `RunRepository`, `GraphSource`, `Queue`, `Clock`, `Workflows` (adapters `Db…`, `Version…`, `ActionScheduler…`, `System…`, `Repository…`).
    - `RunPresenter` — REST shape of runs and steps (camelCase, ISO dates; no scope or cursor). Routes `GET /workflows/{id}/runs`, `GET /runs/{id}`.
- `includes/Pro/Workflow/` — namespace `YayMatik\Pro\Workflow\`, left out of the free zip. `Modules/YayMatikProModule` / `WoocommerceProModule` extend the free modules (empty until Pro has steps); other folders appear with the first Pro file.

## Admin shell

- `./run.sh setup` (`composer install` + `./vendor/bin/yaycommerce-update`; `pnpm dev` runs it when `vendor/autoload.php` is missing) → `vendor-prefixed/` (namespace `YayMatikScoped\`). Both `vendor/` and `vendor-prefixed/` are gitignored.
- Do not call `add_menu_page`. AdminShell owns the YayCommerce menu, Help, Recommended Plugins, and the plugin action/row links.

## Rules

- Classes use `includes/Utils/SingletonTrait.php` and add hooks in the constructor.
- `vendor/` is dumped classmap-authoritative: after adding a PHP class, run `composer dump-autoload`.
- Every REST route needs `permission_callback` (see `RestAPI::can_manage`).
- Changing `yayMatikMeta` → update `apps/admin-page/src/types/localize.d.ts` and run `./run.sh meta-fixture`.
- PHP tests: `pnpm test:php` (plain PHPUnit, no WordPress; `tests/`). Real-site checks (temporary data, removed after): `./run.sh check-storage`, `check-orders` (prints run steps, emails, notes and meta as JSON; mail goes to the local mail catcher), `check-status-flow`, `check-runs` (events → Dispatcher → Worker).
- Style: `phpcs.xml` (WPCS, 4 spaces, PHP 7.4+).
