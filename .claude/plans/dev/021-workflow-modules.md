# Plan: Workflow modules

## Goal
Replace `Extend/` with a module registry (`ModuleRegistry` + `Modules/`), put what modules register (triggers, conditions, actions, data items) at the top level and the engine under `Core/`, and add a Pro folder that the free zip leaves out. Behavior stays the same, and stored step ids (`data.block`) don't change.

## PR #21 check (step 0)
`git fetch origin pull/21/head:pr-21` → 2 commits on top of develop@92b662d (PR #20):
- `35bcdb8` #Extension: module registry. This is the source.
- `2de3b82` Merge origin/develop into guabin/extension. **This commit comes after 35bcdb8.** It merges develop@92b662d (PR #19 M1 PR A + #20) into the branch. Its message says Send email and Generate coupon move into `WoocommerceModule`, `module()` is dropped, `plugin()` is the module id, and meta.json is regenerated. It has no new design, only conflict resolution. It still lacks develop's #22 (M1 PR B) and #23 (conventions). We take nothing from it beyond what is listed below.

Both commits carry PR #21's meta.json. That fixture also lost the `bacs` / `cheque` payment options, which is a site difference, not a code change. Ours must not.

## Approach

### PHP
- **Layout (decision B).** The top level of `includes/Workflow/` holds what modules register; `Core/` holds the engine. Each folder is named for the plural of the base class it holds, and namespaces follow folders.
  ```
  includes/Workflow/
  ├── ModuleRegistry.php
  ├── Modules/      Module, YayMatikModule, WoocommerceModule
  ├── Triggers/  Conditions/  Actions/
  ├── DataItems/    OrderData, CustomerData, OrderItemData, SiteData
  └── Core/         Steps/ (contract)  Data/ (DataItem, DataRegistry, Scope, Template, Type, Filters, Operators, Ref…)  Definition/  Runtime/  Storage/  Email/  Coupon/
  ```
  Pro copies the top level only once a file needs a folder. `tests/Unit` follows `includes/` (minus `Workflow/`).
- **`Modules/Module.php`** (abstract, `YayMatik\Workflow\Modules`). Abstract: `id() label() description() logo()`. `logo()` is the **URL of a one-colour SVG file the plugin ships** (decision A), so a Yay plugin can define its module in its own repo. Defaults: `active()` true; `triggers() conditions() actions() data_items()` return `[]`. There is no tier, no on/off, no `steps()`, no LOGO_TAGS / SVG_OPEN / clean().
- **Logos:** `assets/logos/woocommerce.svg` and `assets/logos/yaymatik.svg` hold today's drawings (viewBox 0 0 24 24, stroke currentColor). The modules return `YAYMATIK_PLUGIN_URL . 'assets/logos/<id>.svg'`. The catalog plugin entry keeps `logo`, which is now a URL (`esc_url_raw`). `assets/logos/` ships in the release zip.
- **`YayMatikModule`** (`yaymatik`) has `conditions()` = Criteria and `data_items()` = SiteData. **`WoocommerceModule`** (`woocommerce`) has `active()` = `class_exists('WooCommerce')`, `triggers()` = OrderCreated and OrderStatusChanged, `actions()` = all 6 in today's picker order, and `data_items()` = OrderData, CustomerData and OrderItemData. Neither class is `final`: Pro extends them.
- **`ModuleRegistry.php`** (`YayMatik\Workflow`)
  - `HOOK = 'yaymatik_modules'`.
  - `classes()` = `apply_filters( HOOK, class_exists( YayMatikProModule::class ) ? [ Pro, Pro ] : [ YayMatikModule, WoocommerceModule ] )`.
  - `from( array $classes ): Module[]` instantiates only `is_subclass_of( $c, Module::class )`, and on a duplicate id the first one wins.
  - `modules()` caches `from( classes() )`.
  - Step building: for each module, take triggers, then conditions, then actions. Keep a class only if it is the matching `Trigger` / `Condition` / `Action`. On a duplicate step id the first one wins. The registry records **step id → module id**, and `plugin( $step_id )` returns it.
  - `data()` builds data items (from each module's `data_items()`) with the same rule.
  - `to_client()` gives the plugin entries (same keys as today).
- **Data items vs entities.** Today `items()` = order, customer, site and `entities()` adds order_item, which is reachable only through `order.items`. Since order_item now sits in `WoocommerceModule::data_items()`, `DataItem` gets `standalone(): bool` (default true). `OrderItemData` returns false. `DataRegistry::entities()` = every module data item and `items()` = the standalone ones. Without the flag, order_item would turn up in the catalog's `data` and in the validator's top-level paths, which changes behavior.
- **Step contract** (`Core/Steps/`, namespace `YayMatik\Workflow\Core\Steps`): `Step` drops `plugin()` and `module()`. `Action::client_meta()` and `Trigger::client_meta()` drop `'plugin'`. `Catalog::step( Step, string $plugin )` returns `[ kind, plugin ] + client_meta() + [ label … ]`, so the key order stays the same as now, minus `module`. `Catalog::steps()` reads the plugin from `ModuleRegistry::plugins( modules() )`. `StepRegistry::all()` and `DataRegistry::items()/entities()` read from `ModuleRegistry`. `from_manifest()` goes away.
- **Pro**: `includes/Pro/Workflow/Modules/YayMatikProModule.php extends YayMatikModule` and `WoocommerceProModule extends WoocommerceModule`, namespace `YayMatik\Pro\Workflow\Modules`. They autoload through the existing PSR-4 `YayMatik\` → `includes/`. **Bodies are empty for now** (they inherit the parent lists). The `array_merge( parent::actions(), [ … ] )` override comes with the first Pro step, because an override that merges `[]` is code "for later". `Pro/Workflow/{Triggers,Actions,Conditions}/` are not created yet: git can't hold empty folders, and a `.gitkeep` would be noise. See open question 1.
- **Order.** The leader's list puts `yaymatik` first, so the catalog's `plugins`, `steps` and `data` come out in a new order: yaymatik/site/condition first. I checked: the app only looks these up by id. The picker and step order come from the TS registry (`coreSteps`), and variables follow `provides`. So the reorder has no UI effect. `StepRegistry::triggers()` keeps OrderCreated before OrderStatusChanged.

### TS (from PR #21, adapted)
- `steps/index.ts`: `coreSteps` + `moduleSteps` (catalog triggers and actions without a TS folder → `defineStep({ id, kind })`).
- `packages/types`: `StepId` and `WorkflowPlugin` widened with `| (string & {})`.
- `PluginIcon`: `tones` becomes `Partial`, unknown plugins get the neutral tile `bg-muted text-foreground`, and it returns null when the plugin is unknown. It draws the logo URL with CSS `mask-image` + `bg-current` (keeps tones, theme, dark mode), with no `dangerouslySetInnerHTML`.
- `StepPicker` guard: `!!plugins[stepDef.plugin]?.active`.
- `localize.d.ts` drops `module`. Comments in it that point at `Extend\Plugins` and `includes/Workflow/Steps/<Kind>/` move to the new paths. So does the path to `StepIcons.php` in `parity.test.ts`.

### Release
- `.distignore` gets `/includes/Pro`.
- `release.sh` takes `pro` as an optional argument. With `pro`, rsync reads `.distignore` without that line and the zip is `release/yaymatik-pro.zip` (folder still `yaymatik/`). Without it, nothing changes: `release/yaymatik.zip` has no Pro classes. The classmap is built after rsync, so `class_exists( YayMatikProModule::class )` is false in the free zip.
- Note: in this repo (dev) the Pro classes always exist, so the dev site runs the Pro set. That is the same steps until Pro adds any.

## File-by-file mapping (old → new)
CP1 contains only `git mv`, so history stays attached.

| Old | New |
|---|---|
| includes/Workflow/Steps/Core/Step.php | includes/Workflow/Steps/Step.php |
| includes/Workflow/Steps/Core/Action.php | includes/Workflow/Steps/Action.php |
| includes/Workflow/Steps/Core/Trigger.php | includes/Workflow/Steps/Trigger.php |
| includes/Workflow/Steps/Core/Condition.php | includes/Workflow/Steps/Condition.php |
| includes/Workflow/Steps/Core/Field.php | includes/Workflow/Steps/Field.php |
| includes/Workflow/Steps/Core/StepResult.php | includes/Workflow/Steps/StepResult.php |
| includes/Workflow/Steps/Core/StepIcons.php | includes/Workflow/Steps/StepIcons.php |
| includes/Workflow/Steps/Core/StepRegistry.php | includes/Workflow/Steps/StepRegistry.php |
| includes/Workflow/Steps/Triggers/OrderCreated.php | includes/Workflow/Triggers/OrderCreated.php |
| includes/Workflow/Steps/Triggers/OrderStatusChanged.php | includes/Workflow/Triggers/OrderStatusChanged.php |
| includes/Workflow/Steps/Triggers/OrderTrigger.php | includes/Workflow/Triggers/OrderTrigger.php |
| includes/Workflow/Steps/Actions/AddOrderNote.php | includes/Workflow/Actions/AddOrderNote.php |
| includes/Workflow/Steps/Actions/ChangeOrderStatus.php | includes/Workflow/Actions/ChangeOrderStatus.php |
| includes/Workflow/Steps/Actions/GenerateCoupon.php | includes/Workflow/Actions/GenerateCoupon.php |
| includes/Workflow/Steps/Actions/SendEmail.php | includes/Workflow/Actions/SendEmail.php |
| includes/Workflow/Steps/Actions/SendInternalEmail.php | includes/Workflow/Actions/SendInternalEmail.php |
| includes/Workflow/Steps/Actions/UpdateOrderMeta.php | includes/Workflow/Actions/UpdateOrderMeta.php |
| includes/Workflow/Steps/Conditions/Criteria.php | includes/Workflow/Conditions/Criteria.php |
| includes/Workflow/Extend/Plugins.php | includes/Workflow/Modules/Module.php (logo tags, SVG_OPEN, clean) |
| includes/Workflow/Extend/Modules.php | includes/Workflow/ModuleRegistry.php (the step list moves into the modules) |
| tests/Unit/Extend/PluginsTest.php | tests/Unit/ModuleRegistryTest.php (logo + to_client tests kept, registry tests added) |
| tests/Unit/Extend/ModulesTest.php | deleted in CP2 (manifest gone; its cases are rewritten in ModuleRegistryTest) |

New in CP2: `assets/logos/{woocommerce,yaymatik}.svg`, `Modules/YayMatikModule.php`, `Modules/WoocommerceModule.php`, `includes/Pro/Workflow/Modules/YayMatikProModule.php`, `includes/Pro/Workflow/Modules/WoocommerceProModule.php`. Deleted: `includes/Workflow/Extend/` (empty after the moves).

Edited for namespaces and `use` (CP2): Engine/BEPages/AdminPage.php, Definition/{Catalog,GraphSchema,Validator}.php, Email/EmailPreview.php, Runtime/{Dispatcher,Executor}.php, Storage/WorkflowRepository.php, Data/{DataRegistry,DataItem,OrderItemData}.php, every moved file. Tests: Support/{FakeStep,FakeCondition}.php, Unit/Definition/CatalogTest.php, Unit/Runtime/{DispatcherWorkerTest,ExecutorTest}.php, Unit/Steps/*.php (FieldTest, GenerateCouponTest, SendEmailTest, StepConfigsTest, StepContractTest, StepResultTest, StepRunTest, TriggerRefsTest), stubs.php (`add_filter` / `apply_filters` that run callbacks).

### CP2b moves (decision B, `git mv` only; 87 renames)
| Old | New |
|---|---|
| includes/Workflow/{Steps,Definition,Runtime,Storage,Email,Coupon}/ | includes/Workflow/Core/{same}/ |
| includes/Workflow/Data/{OrderData,CustomerData,OrderItemData,SiteData}.php | includes/Workflow/DataItems/ |
| includes/Workflow/Data/ (the rest) | includes/Workflow/Core/Data/ |
| tests/Unit/Data/CustomerDataTest.php | tests/Unit/DataItems/CustomerDataTest.php |
| tests/Unit/{Data,Definition,Email,Runtime,Steps,Storage}/ | tests/Unit/Core/{same}/ |

CP2c: namespaces `YayMatik\Workflow\{Steps,Definition,Runtime,Storage,Email,Coupon,Data}` → `YayMatik\Workflow\Core\…`, the 4 data items → `YayMatik\Workflow\DataItems`, and test namespaces to match, across includes/, tests/ and dev/scripts. Data items and `Core\Data` now `use` each other (they used to share a namespace). Tests one folder deeper get `dirname( __DIR__, 3 )`.

## From PR #21: reused / changed / dropped
**Reused**
- TS: `steps/index.ts` coreSteps + moduleSteps, `moduleSteps.test.ts`, widened `StepId` / `WorkflowPlugin`, `PluginIcon` neutral tile + unknown guard, `StepPicker` guard, `localize.d.ts` without `module`.
- PHP: first owner wins for module / step / data ids. `StepRegistry` and `DataRegistry` read from the registry. Catalog drops `module`. ModuleRegisterTest's `to_client`, first-owner and registries-read-modules cases.
- The `apply_filters` stub idea (extended so the filter test can add a callback).

**Changed**
- `ModuleRegister` → `ModuleRegistry`. `ModuleInterface` → `abstract class Module` (defaults only). `MatikModule` → `YayMatikModule`.
- `steps()` → `triggers() / conditions() / actions()`. `data_items()` returning instances → `data_items()` returning class names.
- Filter value: instances → class names, with the Pro swap first. Non-Module entries are ignored.
- Steps don't declare `plugin()`: the registry sends the owner module as `plugin`. PR #21 kept `plugin()` = module id.
- Modules are listed with `yaymatik` first (leader's list), where PR #21 had woocommerce first.
- order_item is in a module's `data()` with `standalone()` false. PR #21 kept it hard-coded in `DataRegistry::entities()`.
- Folders: Triggers/ Actions/ Conditions/ at the top level and Steps/ = contract only. PR #21 kept `Steps/{Core,Actions,…}`.
- `moduleSteps.test.ts` uses a neutral `example` plugin, not a product name.
- meta.json is regenerated on our site (bacs/cheque kept); PR #21's fixture is not reused.

**Dropped**
- `tier()`, `enabled()`, `TIER_LITE/PRO`, `test_core_modules_are_lite_and_on`, `test_steps_never_check_the_tier` (Free vs Pro is the folder).
- `test_each_step_names_its_module` (steps no longer name it).
- Merge commit 2de3b82 and both of PR #21's meta.json fixtures.
- PR #21's doc edits (rewritten in CP5).
- Inline SVG logos: LOGO_TAGS, SVG_OPEN, `clean()` and the kses logo tests (logos are files now; a test checks `logo()` is a URL ending in `.svg` and the file exists).

## Tests
- `tests/Unit/ModuleRegistryTest.php`:
  - free set: `from([YayMatikModule, WoocommerceModule])` → ids `yaymatik, woocommerce`, today's 9 step ids, data `order, customer, site` + entity `order_item`
  - Pro set: `classes()` returns the Pro classes when they exist (they do under tests), and `from()` of those gives the same ids and steps
  - a filter callback adds a test module → its steps register with its id as `plugin`
  - a non-Module class and a non-class string are ignored
  - duplicate module / step / data ids keep the first
  - `to_client` keys kept from PluginsTest; each logo is `YAYMATIK_PLUGIN_URL . 'assets/logos/<id>.svg'` and the file exists
- `CatalogTest`: the key-order assertions lose `module`, and `plugin` comes from the registry. A new case: `Catalog::to_client()`'s `plugins` and, per step, `kind`, `plugin` and its key list equal `meta.json` (read from the fixture). Full equality is checked once in CP3 (below), because WC option lists depend on the site, so a PHPUnit full compare would break under the stubs.
- `moduleSteps.test.ts` (Vitest).
- Every existing PHPUnit and Vitest test passes unchanged apart from namespaces/paths.

## Checkpoints
1. ✅ **CP1 Moves** — Risk: 1. Branch `jack/refactor/workflow-modules` off develop, then only the `git mv` rows above. Build/tests may break. Then `composer dump-autoload --optimize --classmap-authoritative`. Commit only when Jack says: `refactor: plan 021 CP1 — move steps, triggers, actions, conditions, Extend`.
2. ✅ **CP2 PHP** (committed 156d32f) — Risk: 2. Module, YayMatikModule, WoocommerceModule, ModuleRegistry, the Pro modules, `standalone()`, the step contract without plugin/module, Catalog plugin from the registry, StepRegistry/DataRegistry, namespaces and uses, PHP tests. PHPUnit + phpcs + `php -l` green; `composer dump-autoload --optimize --classmap-authoritative`, then the site loads (admin page + editor, wp-cli `wp eval` of `Catalog::to_client()`). Commit trailer `Co-authored-by:` a co-author.
   - ✅ **CP2b Layout moves** — Risk: 1. `git mv` only (table above). Committed 346fb20.
   - ✅ **CP2c Namespaces** — Risk: 1. Namespaces and uses in includes/, tests/, dev/scripts; dump-autoload; PHPUnit, phpcs, `php -l`; admin page loads. Also:
     - The dispatcher registers trigger listeners on `init` (priority 0), not `plugins_loaded`, so a plugin loaded after YayMatik can still add its module on `plugins_loaded`. Nothing else reads the registry before `init`. Checked by `./run.sh check-late-module` (filter on `plugins_loaded` 20 → module, trigger and listener present; fails with the old timing).
     - `ModuleRegistry::build()` of the loaded modules runs once (steps instantiated once).
     - Single-step tests moved: SendEmailTest, GenerateCouponTest → tests/Unit/Actions/; TriggerRefsTest → tests/Unit/Triggers/. Commit only when Jack says: `refactor: plan 021 CP2c — namespaces follow Core/ and DataItems/`.
3. ✅ **CP3 Catalog + TS** — Risk: 1. PR #21's TS parts, PluginIcon mask-image logos, path comments (incl. `parity.test.ts` → `includes/Workflow/Core/Steps/StepIcons.php`), `./run.sh meta-fixture`, CatalogTest fixture case.
   - One-off check: `jq -S` of the old vs new `automation` with `module` deleted from the old one must be identical, apart from object order and `plugins[].logo` (SVG markup → URL).
   - `pnpm build`, `pnpm lint`, `pnpm test` green. Khoa trailer.
4. ✅ **CP4 Release** — Risk: 1. `.distignore` `/includes/Pro`, `./run.sh release` and `./run.sh release pro`. Check with `unzip -l` that `includes/Pro/` is absent in the free zip and present in the pro one, that `assets/logos/` is in both, and that Pro classes are in the free classmap 0 times. Install release/yaymatik.zip (free) on the local site once: YayMatik page, editor, picker logos, one template install. Report, then restore the dev plugin.
5. ✅ **CP5 Docs + smoke + PR text** — Risk: 1.
   - Docs: workflow-core-design §1 (folder table: Steps/, Triggers/, Actions/, Conditions/, Modules/, no Extend/) and §8 (modules list instead of the manifest; Free/Pro = folder; filter `yaymatik_modules`; unfinished = not registered); context-php-backend lines 18–24; context-dev-convention "New step" line; ROADMAP-dev F1.7 / M9.3 wording; "add the `yaymatik_modules` filter before `init`" (context-php-backend + §8); `./run.sh release pro` in .claude/docs/ops-release.md.
   - `/wcheck` smoke: editor loads, picker logos (both plugins), one template installs, `./run.sh check-orders`.
   - `.claude/plans/dev/021-pr.md` with "Beyond the leader's layout": Triggers/ folder, `data_items()` in modules, `Core/` for the engine with `Core/Steps/` = contract only, `DataItems/` at the top level, folder = plural of its base class, steps drop plugin(), logos as SVG files (a Yay plugin can define its module in its own repo), TS auto-registers catalog steps (PR #21), `./run.sh release pro`, one line of why each. Also lists `standalone()`, and notes the Pro modules have empty bodies and the Pro Triggers/Actions/Conditions folders appear with the first Pro step.

## Follow-ups
- Logos are drawn with CSS `mask-image`, which loads the SVG in CORS mode. A CDN that serves plugin assets from another origin without `Access-Control-Allow-Origin` blocks the mask, and the tile shows a solid block. Check in M9 (release hardening).
- Browser pass of the free zip (editor, picker logos, a template install clicked in the UI): not done in CP4 (no browser tools in that session); covered by the CP5 `/wcheck` smoke on the dev build.

## Out of Scope
- Any new Pro step, template or data item. Pro Triggers/Actions/Conditions folders until they hold a file.
- Templates owned by modules, the feature directory, `registerStepPanel` (V12.x).
- Merging or rebasing PR #21. Closing it is up to Khoa / the leader.
- Changing the plugin header or slug for the Pro zip.

## Decisions (Jack, review)
- Pro modules: empty bodies; no Pro/Workflow/{Triggers,Actions,Conditions} until they hold a file.
- `standalone()` on DataItem.
- `composer dump-autoload` (classmap-authoritative) after CP1 and CP2; the site must load, not only PHPUnit.
- A: logos are SVG files (`logo()` = URL), drawn with mask-image in CP3; `Module::data()` → `data_items()` (catalog key `data` unchanged).
- B: top level = what modules register, `Core/` = the engine; folder = plural of its base class.
- No commit until Jack says.

## Meta
- Target branch: jack/refactor/workflow-modules
- Base branch: develop
