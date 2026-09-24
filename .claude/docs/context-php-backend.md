# PHP Backend

`src/` maps to namespace `YayAutomation\` via `autoloader.php`.

## Key Paths

- `yay-automation.php` — constants, autoloader, `plugins_loaded` boot
- `src/YayAutomation.php` — entry; wire new engine classes here; `is_prod()`
- `src/Engine/BEPages/AdminPage.php` — menu, capability, `yayAutomationMeta`, mount div
- `src/Engine/API/RestAPI.php` — routes under `yay-automation/v1`
- `src/Engine/Register/` — script loading; `RegisterDev.php` is stripped from the release zip

## Rules

- Classes use `src/Utils/SingletonTrait.php` and add hooks in the constructor.
- Every REST route needs `permission_callback` (see `RestAPI::can_manage`).
- Changing `yayAutomationMeta` → update `apps/admin-page/src/types/localize.d.ts`.
- Style: `phpcs.xml` (WPCS, 4 spaces, PHP 7.4+).
