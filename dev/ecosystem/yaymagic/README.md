# YayMagic: how an extension adds its own UI

The action "Send email designed in YayMagic" with a **Customize email** button that opens YayMagic's own editor in YayMatik's full-screen modal. Public Extension API only (`docs/extension-api.md` §5.5 email, §5.6 iframe editor).

What the YayMagic team keeps and what it replaces:

- `YayMagic_YayMatik.php`: the declarations. Copied as it is.
- `YayMagic_YayMatik_Fake_Adapter.php` and `editor.php`: stand-ins. The real adapter points at YayMagic's own editor and designs.

## The flow

1. **Hook.** `load.php` adds `YayMagic_YayMatik::register()` to `yaymatik_register`. It fires only when YayMatik is active.
2. **Module.** `register()` calls `$registrar->module()` with `requires_api => '1.0'`. When `ok()` is false, YayMatik refused it and Settings › Health check says why.
3. **Action.** `$module->action( 'yaymagic_send_email', … )` declares:
   - `settings.design_id`: a `select`; its options come from `design_options()`.
   - `email`: YayMatik sends the email. `build_email()` returns the subject and HTML, recipient `customer`, so it works on any trigger with a customer. YayMatik renders its `{{ … }}` tags, wraps the email, adds unsubscribe and the mark.
   - `validate`: `validate( $config, $available )` refuses a design with order blocks when `order` isn't in `$available`. The fake reads any `{{ order.… }}` tag as an order block. The error shows on the Design field after the next save.
   - `editor`: `field => 'design_id'`, the button label, and `url => editor_url()`. The URL must be on this site's admin: YayMatik refuses another origin.
4. **Open.** The button opens `editor_url()` in the modal, with `yaymatik_embed=1&yaymatik_channel=…` added.
5. **`ready` → `init`.** `editor.php` posts `ready`. YayMatik replies `init` with the step's `value` and unsaved `config`, the `sample` order and customer ids, the `locale`, and `variables`. The fake shows them under "From YayMatik".
6. **Variables.** `init.variables` is the same list as Insert variable for that step: trigger data, then outputs of steps above it (Set variable, For each item…). A click inserts `{{ path }}` into the body. YayMatik renders it when it sends.
7. **`change`.** Every edit posts `change` with `dirty: true`, so closing the modal asks first.
8. **`save`.** The form posts to `save_from_editor()`, which stores the design (`save_design()`) and reloads the page with `saved`. The page then posts `save` with the design id as `value` and its name as `label`. YayMatik puts the id in `config.design_id`, shows the name in the Design field, closes and refreshes Preview.
9. **`cancel`.** Cancel posts `cancel`.
10. **Send.** A run calls `build_email()` in a background job. Use `$step->order()` / `$step->user()` there, never globals. A design that no longer exists throws, and the step fails with "Couldn't build the email."

The page checks every message as YayMatik does: same origin, from `window.parent`, `source: 'yaymatik-editor'`, its own channel.

## Design changes are live

The design lives in YayMagic, not in the workflow version: editing it changes live emails at once. The panel says so under the button. `\YayMatik\Api\Api::references( 'yaymagic_send_email', 'design_id' )` lists the design ids any version still uses, before YayMagic deletes one.

## Check it

- `tests/Unit/Ecosystem/YayMagicEditorTest.php`: `build_email`, `save_design`, `validate`, `editor_url`.
- `./run.sh check-ecosystem --wp-env`: the YayMagic cells send a design saved through `save_design()` from a background run, its variables rendered.
- In the browser (dev mode, `pnpm dev`): add the action, click **Customize email**, insert a variable, Save. The Design field shows the name and Preview shows the design.
