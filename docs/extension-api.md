# YayMatik Extension API v1 — contract

**Status:** **agreed by Jack, Nov 13 10:25; public surface simplified Nov 13 11:47** (arrays, `yaymatik_fire`, `Run`) (written Nov 12 from develop 6be688a). Ships in YayMatik **1.1** (after WordPress.org approves 1.0). Decisions Q1–Q12 in §15, their reasons in [workflow-core-decisions.md § EX](workflow-core-decisions.md); checkpoints and the two PRs in §17 and `.claude/plans/dev/034-extension-api.md`.

Labels: **[Certain]** = read in code / official docs; **[Likely]** = standard practice or strong inference; **[Guess]** = depends on a plugin's code we have not seen.

---

## 1. Goals and rules

- A Yay plugin (YayMail, YayMagic, YayCurrency, YayPricing, YayWholesale) or a third-party plugin adds **triggers, actions, data items, data fields (conditions + merge tags), templates and an iframe editor** to YayMatik from its own code (J6), in free and Pro alike (J10).
- **Declarative** (J3): an extension passes args arrays and callables to a registrar. It never extends a YayMatik class. Core wraps the callables in internal adapters, so internal classes can change without breaking anyone.
- **Stable and narrow** (J2, G6): everything in this file is semver'd. Anything not in this file is internal, even when it is technically reachable (`YayMatik\Workflow\*`, every `yaymatik_*` hook not listed in §11).
- **A broken extension never breaks core** (§9); **a deactivated extension never breaks a workflow** (§10, J9).
- **Runs look the same** for core and extension steps: canvas summary, run detail, Skipped / Failed reasons, unsubscribe, mail log, privacy export / erase (G2, G5).

## 2. Entry point

```php
add_action( 'yaymatik_register', function ( \YayMatik\Api\Registrar $r ) {
    if ( ! $r->supports( 'email' ) ) {
        return; // Older YayMatik: register nothing rather than half.
    }
    $m = $r->module( [
        'id'           => 'yaymail',
        'label'        => 'YayMail',
        'description'  => __( 'Emails designed in YayMail.', 'yaymail' ),
        'logo'         => plugins_url( 'assets/yaymatik-logo.svg', __FILE__ ),
        'requires_api' => '1.0',
    ] );
    $m->action( 'yaymail_send_email', [ /* §5 */ ] );
    $m->template( 'yaymail_order_paid', [ /* §7 */ ] );
} );
```

A trigger is started from the plugin's own code, where the event happens (§5.3):

```php
do_action( 'yaymatik_fire', 'yaywholesale_approved', [ 'user_id' => $user_id, 'role' => $role ] );
```

- `yaymatik_register` fires **once**, the first time the registry is read (at the latest `init` priority 0; Runtime.php:38–39). Hook it on `plugins_loaded` or at file load. A plugin loaded after YayMatik still works. **[Certain]**
- If YayMatik is not active neither hook does anything: the integration needs no `class_exists` check. **[Certain]**
- Registering after the action has fired → ignored + `_doing_it_wrong()` + a diagnostic (§9).
- `$r->module()` always returns a `Module` handle. When the module is rejected (bad id, `requires_api` not met, …) the handle is inert: its methods accept calls and do nothing, so the plugin never fatals on a `WP_Error`. `$m->ok(): bool` tells which.
- Declarations are **arrays**; every callback is any PHP callable: a closure, `[ $this, 'method' ]` or a function name. A plugin can keep its integration in one class with named methods.

## 3. Versioning, deprecation, compatibility

| Item | Rule |
|---|---|
| API version | `\YayMatik\Api\Api::VERSION` (e.g. `'1.0.0'`), independent of `YAYMATIK_VERSION` |
| `requires_api` | Module refused (diagnostic "needs YayMatik with Extension API x.y") when MAJOR differs or the required MINOR is newer |
| Feature detection | `$r->supports( string $feature ): bool`. v1.0 features: `triggers`, `actions`, `data_items`, `data_fields`, `templates`, `email`, `editor_iframe`, `trigger_facts`, `privacy_subjects` |
| MINOR may | add optional declaration keys, field types and data type names, `Run` methods, `supports()` features, postMessage types core ignores if unknown, core steps |
| MAJOR only | remove or rename a key, a class, a method, a hook; make an optional key required; change a callback's parameters or return meaning; change a stored id or config key (core or extension) |
| Callback args | Core only **appends** parameters, never reorders. Parameters are arrays, scalars, the interfaces in §4 or WordPress / WooCommerce objects — never internal classes |
| Unknown declaration key | Ignored; `_doing_it_wrong()` under `WP_DEBUG` (an extension written for 1.2 still loads on 1.1; it checks `supports()` for the feature) |
| Deprecation | `_deprecated_argument()` / `_deprecated_hook()` / `_deprecated_function()`; kept at least **2 MINOR releases and 6 months**; removed only in the next MAJOR; listed in §16 Changelog |
| Next MAJOR | New entry hook `yaymatik_register_v2`; `yaymatik_register` keeps firing for one more MAJOR |
| Stored ids | Step ids, config keys, data item ids, field keys, output keys and template ids are **never renamed** once released — by core (§12) or by the extension. Renaming a config key makes the next save drop the stored value (core keeps declared keys only). Add a new key and read the old one instead |

## 4. Public surface (namespace `YayMatik\Api`)

A plugin touches **arrays, one `do_action` and one `Run` object**. Everything else (`YayMatik\Workflow\*`) is internal: core converts the arrays into its own field, type and ref classes, so those can change.

| Piece | Surface |
|---|---|
| hook `yaymatik_register` → `Registrar` | `module( array ): Module`, `supports( string ): bool`, `api_version(): string` |
| `Module` | `ok()`, `trigger( id, array )`, `action( id, array )`, `data_item( id, array )`, `data_fields( item_id, array )`, `template( id, array )`, `category( id, label )`. Each returns `bool` |
| hook **`yaymatik_fire`** | `do_action( 'yaymatik_fire', string $trigger_id, array $payload )` — starts a trigger (§5.3) |
| `Run` (interface) | The one object `run` and `build` get (§4.3) |
| `CustomerView`, `CartView` (interfaces) | What data-field callbacks on `customer` / `cart` receive (§6) |
| `Api` | `Api::VERSION`, `Api::references( step_id, field )` (§5.6) |

### 4.1 Settings fields

A step's form, keyed by config key:

```php
'fields' => [
    'role' => [
        'type'     => 'select',
        'label'    => __( 'Role', 'my-plugin' ),
        'options'  => [ $this, 'role_options' ], // an array, or a callable returning one
        'required' => true,                      // or a message string
        'default'  => 'silver',
    ],
],
```

| Key | Meaning |
|---|---|
| `type` | `text`, `textarea`, `html`, `number`, `select`, `multiselect`, `toggle`, `emails`, `notice`, `number_unit` |
| `label` | Required |
| `options` | `select` / `multiselect`: value ⇒ label, or a callable returning that |
| `units` | `number_unit`: unit ⇒ label; the first is the default |
| `text` | `notice`: the text shown |
| `required` | `true`, or the message to show when blank (`%s` = the step's label) |
| `default`, `help`, `placeholder`, `min`, `max` | As named |
| `variables` | `true`: accepts `{{ … }}` merge tags (Insert variable) |
| `show_if` | `[ field => value ]` (value or list of values): shown only then; a hidden field is never checked |

### 4.2 Data types

Facts, outputs and data fields name their type as a string: `string`, `number`, `money`, `boolean`, `date`, `enum`, `list` (with `'of' => 'string'`, any type name) and `entity` (with `'item' => 'order'`). Condition operators come from the type. An unknown name refuses the declaration (§9).

### 4.3 `Run`

| Method | Returns |
|---|---|
| `order()` | `WC_Order`, or null (no order in this run, or made-up preview data) |
| `user()` | the customer's `WP_User`: the order's account when the run has an order with one, else the payload `user_id`'s user; null for a guest |
| `customer_email()` | string, `''` when none |
| `cart()` | `CartView`, or null |
| `item( string $id )` | the loaded object of the module's own data item (e.g. `yaywholesale_request`), or null; core items have the getters above |
| `get( string $path )` | any value by merge-tag path: `trigger.role`, `customer.first_name`, `steps.coupon.code`; a path to an entity (`order`) is null |
| `render( string $text, bool $html = false )` | `$text` with its `{{ … }}` merge tags filled (escaped for HTML when `$html`) |
| `is_preview()` | true in Preview / Send test |
| `done( array $outputs = [] )` | finish the step; outputs must be declared |
| `skip( string $reason )` | nothing to do; the run goes on (G5) |
| `fail( string $reason )` | an error; `on_error` decides |
| `unsubscribe_url()`, `is_test()` | email actions only (§5.5) |

`run` returns what `done()` / `skip()` / `fail()` return. Anything else → Failed "The step returned no result." + diagnostic.

## 5. Steps

### 5.1 Ids and ownership
- Every id an extension declares starts with **its module id + `_`**: `yaymail_send_email`, `yaycurrency_switched`, data item `yaywholesale_request`, field `yaycurrency_rate` on `order`. Core enforces it, so a step always belongs to the plugin it needs (proposal finding 6) and core can never collide with it later. Ids: `^[a-z][a-z0-9_]{2,63}$` (merge-tag paths allow `[a-z_][a-z0-9_]*`, Template.php:17). **[Certain]**
- Reserved module ids: `yaymatik`, `woocommerce`, `wordpress`, `core`, `steps`, `trigger`, `run`, `site`.
- First owner wins (as today, ModuleRegistry.php:56,91,147), but a second claim now records a diagnostic instead of disappearing silently.

### 5.2 Keys shared by triggers and actions

| Key | Required | Meaning |
|---|---|---|
| `label`, `description` | yes | Translated strings |
| `icon` | no | A name from core's icon list (`StepIcons::NAMES`, published in the docs); default the module logo |
| `category` | no | A core category or one the module declared with `$m->category()` (open list, labels from the server; StepPicker.tsx:25–33 and localize.d.ts:78 become open) |
| `fields` | no | The settings form, §4.1 (server-driven, no JS) |
| `summary` | no | Canvas line with `%field%` placeholders (Step.php:42) |
| `validate` | no | `fn( array $config, array $available ): array` — checks fields can't express. Return `[]` when fine, or `[ field_key => message ]` (`''` as key for a step-level message). `$available` = data item ids the step can read there (from the trigger and steps above), e.g. to say "this design uses order blocks, but this workflow's trigger has no order" |
| `setup_issue` | no | `fn(): string` — `''` when fine, else why it can't run on this site (shown on Activate) |

### 5.3 Trigger

A trigger is a description; the plugin starts it with `yaymatik_fire`.

```php
$m->trigger( 'yaywholesale_approved', [
    'label'       => __( 'Wholesale request approved', 'yaywholesale' ),
    'description' => __( 'Starts when you approve a customer as a wholesaler.', 'yaywholesale' ),
    'category'    => 'customer',
    'provides'    => [ 'customer' ],
    'facts'       => [ 'role' => [ 'label' => __( 'Approved role', 'yaywholesale' ), 'type' => 'enum', 'options' => [ $this, 'role_options' ] ] ],
    'once_per'    => 'subject',
] );
```

| Key | Required | Meaning |
|---|---|---|
| `provides` | yes | Data item ids every run has, from `customer`, `order`, `cart`, `review` and the module's own data items, e.g. `[ 'customer' ]` |
| `facts` | no | `[ key => [ 'label', 'type', 'options'? ] ]` — values true only at event time (currency from / to, a form's answers), passed in the payload under the same keys; they read as `trigger.<key>` in conditions, Insert variable and run detail. Limits: scalar types only (string, number, money, boolean, date, enum; for an entity use a payload id); at most 10 keys, each value ≤ 1 KB, JSON-safe (over → cut + diagnostic); **no personal data except emails** (emails are indexed by `run_emails`; names / phones would escape erase — reach a person through `user_id` / `order_id`); keys are stored ids, never renamed; not ending in `_id`, not a data item id; fact keys are per trigger: another trigger may use the same key with another type |
| `matches` | no | `fn( array $payload, array $config ): bool` — this workflow's settings accept the event |
| `once_per` | no | `'subject'` = once per workflow per subject (core `trigger_claims`), default none |
| `sample` | no | Preview values for `facts` |

**Firing:** `do_action( 'yaymatik_fire', $trigger_id, array $payload )`, whenever the event happens.

- YayMatik inactive → nothing happens (a plain `do_action`). No active workflow uses the trigger → returns at once (active-trigger cache): cheap anywhere, checkout included. Firing before `init` is allowed: the event waits for `init`, so plugins that register later in the request are in.
- **Payload keys:** `order_id`, `user_id`, `cart_id`, `review_id`, and `<data_item>_id` for the module's own data items, become the run's data. `customer` comes from the first of order → cart → review → user, as core order triggers do: a `user_id` beside an `order_id` doesn't change `customer` (`Run::user()` still falls back to it for a guest order). Every other key must be a declared fact. Unknown keys are dropped + diagnostic; a payload missing what `provides` needs → no run + diagnostic.
- **Subject** is derived, first found: order → cart → review → user → the module's data item. Core shapes (`order:12`, `cart:9`, `review:3`, `user:5`), so privacy export / erase cover the run with no extra code; a module data item subject needs `privacy_subjects` (§8).
- Payload values are JSON-safe scalars (they go through Action Scheduler). The event goes through the same path as core triggers: collected during the request, runs started at shutdown (Dispatcher.php:77–90), `once_per`, run ceiling.
- Fired from inside a run (an action that causes the event): the loop guard applies; a workflow never restarts itself through its own runs.

**Repeats and throttling are the plugin's job**, before it fires (no core `dedupe` in v1, Q5):
```php
add_action( 'acme_points_earned', function ( $user_id, $points ) {
    $key = 'acme_ym_' . $user_id;
    if ( get_transient( $key ) ) {
        return;                                   // once per customer per hour
    }
    set_transient( $key, 1, HOUR_IN_SECONDS );
    do_action( 'yaymatik_fire', 'acme_rewards_points_earned', [ 'user_id' => (int) $user_id, 'points' => (int) $points ] );
}, 10, 2 );
```
Don't use Skipped runs for this: a Skipped run (Dispatcher.php:205–208) is a refusal a person should see in Workflow runs.

Prefer **a core trigger + a condition** over a new trigger: "Order paid in EUR" is Order paid + `order.currency is EUR`; a new trigger that hooks WooCommerce directly repeats bugs core already fixed (proposal finding 4: `woocommerce_payment_complete` misses COD / BACS). There are no "derived" triggers in v1.

### 5.4 Action

```php
$m->action( 'yaywholesale_set_role', [
    'label'       => __( 'Set wholesale role', 'yaywholesale' ),
    'description' => __( 'Moves the customer to another wholesale role.', 'yaywholesale' ),
    'category'    => 'customer',
    'requires'    => [ 'customer' ],
    'fields'      => [ 'role' => [ 'type' => 'select', 'label' => __( 'Role', 'yaywholesale' ), 'options' => [ $this, 'role_options' ], 'required' => true ] ],
    'summary'     => __( 'Set role to %role%', 'yaywholesale' ),
    'result'      => __( 'Role is now %role%', 'yaywholesale' ),
    'outputs'     => [ 'role' => [ 'label' => __( 'New role', 'yaywholesale' ), 'type' => 'enum' ] ],
    'run'         => [ $this, 'run_set_role' ],
] );

public function run_set_role( array $config, $run ) {
    $user = $run->user();
    if ( ! $user ) {
        return $run->skip( __( 'Guests can’t have a wholesale role.', 'yaywholesale' ) );
    }
    update_user_meta( $user->ID, '_yaywholesale_role', $config['role'] );
    return $run->done( [ 'role' => $config['role'] ] );
}
```

| Key | Required | Meaning |
|---|---|---|
| `requires` | no | Data item ids the action needs whatever its config (e.g. `[ 'order' ]`); an unknown id refuses the declaration |
| `run` | yes unless `email` | `fn( array $config, Run $run )` → `$run->done( outputs )`, `$run->skip( reason )` or `$run->fail( reason )` |
| `outputs` | no | `[ key => [ 'label', 'type', 'options'? ] ]` — readable by later steps at `steps.<node>.<key>`; only declared keys are stored (Executor.php:263) |
| `sample_outputs` | no | `fn( array $config ): array` for previews of later steps |
| `on_error` | no | `'stop'` (default) or `'continue'` |
| `result` | no | Run-detail line with `%output%` / `%field%` placeholders, e.g. `'Sent to %to%'`; default the `summary` (runResults.ts:100) |
| `emits` | no | Trigger ids this action can set off; the editor warns (`may_refire`) when one is its workflow's own trigger. The runtime loop guard needs none: a run never restarts its own workflow through its own events |
| `email` | no | Email capability, §5.5 |
| `editor` | no | Iframe editor, §5.6 |

**Skipped vs Failed (G5):** `$run->skip( reason )` = nothing went wrong, there was nothing to do (unsubscribed, already done, plugin missing); the run goes on. `$run->fail( reason )` = an error; `on_error` decides. A reason is one translated sentence ending with a period, plain text, no HTML, no secrets, no more personal data than the step's own output.

### 5.5 Email capability (G1 + G2)

```php
'email' => [
    'audience'  => 'customer',            // or 'staff': no unsubscribe, no List-Unsubscribe
    'recipient' => 'to',                  // a config field key (merge tags allowed), or 'customer' = {{ customer.email }}
    'build'     => [ $this, 'build_email' ], // fn( array $config, Run $run ): array
],

public function build_email( array $config, $run ): array {
    // $run->order() / $run->user(): null = no order or customer here, or made-up sample data (use your own sample).
    return [
            'subject' => '…',
            'html'    => '…',             // a body (wrap = true) or a whole document (wrap = false)
            'wrap'    => false,           // true: core puts it in WooCommerce's email template with the subject as heading
    ];
}
```

An action that declares `email` gets, with no other code:

| Core does | As today for Send email |
|---|---|
| **Preview** and **Send test** buttons in its panel, same dialog (desktop / mobile) | EmailPreview.php:52–96, B2 panel |
| Same sample: newest paid order with products for an order trigger, newest cart for a cart trigger, **newest customer account for a customer-only trigger** (Q4a; core Send email too), else made-up data (`$run->is_preview()` true, `$run->order()` / `$run->user()` null) | EmailPreview.php:171–228 (order / cart only today) |
| **Merge tags in the returned subject / HTML** (`{{ customer.first_name }}`, `{{ steps.coupon.code }}`) rendered by core with context escaping (Q4b) | Template::render_html, as Send email |
| Validator checks before Preview (this step only) | EmailPreview.php:117–138 |
| At run: render recipient, normalize, **Skipped "The customer unsubscribed from these emails."**, unsubscribe URL, `List-Unsubscribe` + one-click headers, send, **output `to`**, run detail "Sent to …", Failed "No valid email address." / "Couldn't send the email." | SendEmail.php:119–133, 173–193 |
| Send test: logged-in admin's address, subject prefixed `[Test] `, inert unsubscribe link, no List-Unsubscribe | EmailPreview.php:28, 78–96 |
| "Review the emails before you activate" on Use template (`audience` = customer) | meta.ts:30 hard-codes `send_email` today |

Rules:
- **Order of rendering:** the plugin renders its own blocks / shortcodes in `build` → core renders YayMatik merge tags → core adds the unsubscribe footer if the HTML has no `unsubscribe_url()` → send. Merge tags inside the plugin's design are not seen by YayMatik's Validator: a wrong tag renders empty (accepted, Q4b).
- **`build` runs in a background job** (Action Scheduler) when a run sends: no request, no logged-in user, no global `$post`. Blocks and shortcodes take the order / customer from `$run->order()` / `$run->user()`, never from globals. Preview runs in the admin and can hide this bug: test a real run.
- **No order needed:** an email action works with any trigger. Say what a design needs through `validate( $config, $available )` (order blocks need `order` in `$available`).
- **Unsubscribe link:** put `$run->unsubscribe_url()` in your footer (`#` in Preview / Send test; `$run->is_test()` is true in Send test). If the HTML doesn't contain it, core inserts the standard footer before `</body>` (or at the end when `wrap` is true). (Q6, agreed)
- **HTML trust:** email HTML from `build` is **not** run through kses — it comes from the plugin's own PHP, like a WooCommerce email, and a full document needs `<style>` / `<head>`. kses applies to values core inserts into **other** emails (data-field `display` HTML, block values; proposal N3). (Q7, agreed)
- `build` runs inside the isolation of §9: a throw → Preview shows the error, a run step Fails with "Couldn't build the email." + diagnostic.
- Plugins that only re-send their own WooCommerce emails need nothing: core **Resend WooCommerce email** + filter `yaymatik_resendable_emails` already sends any `WC_Email` (ResendWcEmail.php:18), which YayMail already styles. **[Likely]**

### 5.6 Iframe editor (G3, stable per G6)

```php
'editor' => [
    'field'  => 'template_id',                    // the config key the editor fills; a `text` or `select` field the action declares
    'button' => __( 'Customize design', 'yaymail' ),
    'url'    => fn( array $ctx ): string => admin_url( 'admin.php?page=yaymail&embed=yaymatik&template=' . rawurlencode( $ctx['value'] ) ),
],
```

- The URL **must be same-origin** with the admin (`admin_url()`). `url` is a callable, so core checks its result each time it builds the URL: another origin is refused + diagnostic (at registration core only checks that `url` is callable). No cross-origin editors in v1 (no SaaS), so origin checks are exact. **[Certain]** on the browser rule; **[Likely]** that this fits YayMail / YayMagic (both are WP admin apps).
- `$ctx`: `value` (current reference, `''` when none), `workflow_id`, `node_id`, `step` (id), `config` (unsaved config). Core appends `yaymatik_embed=1&yaymatik_channel=<random>`.
- Core opens the URL in a **full-screen modal** iframe (no `sandbox`: the page is the plugin's own trusted admin page; WP admin's `X-Frame-Options: SAMEORIGIN` allows it).

**postMessage schema v1** — every message is `{ source: 'yaymatik-editor', version: 1, channel, type, … }`:

| Direction | `type` | Payload | Core does |
|---|---|---|---|
| iframe → core | `ready` | — | replies `init` |
| core → iframe | `init` | `value`, `config`, `sample: { orderId: number\|null, userId: number\|null }`, `locale`, `variables: [{ path, label, type }]` (merge tags usable at this step, for the editor's own Insert variable; core renders them, Q4b) | — |
| iframe → core | `change` | `value`, `dirty: boolean` | remembers; closing with `dirty` asks to confirm |
| iframe → core | `save` | `value` (string \| number, ≤ 191 chars), `label?` (plain text for the panel) | puts `value` in `config[field]`, closes, refreshes Preview; the next autosave runs the step's `validate` server-side (invalid → field error as usual) |
| iframe → core | `cancel` | — | closes (confirm if dirty) |
| iframe → core | `error` | `message` | shows it in the modal header |

- Core accepts a message only when `event.origin === location.origin`, `event.source === iframe.contentWindow` and `channel` matches; it posts with `targetOrigin = location.origin`. Unknown `type` → ignored (forward compatible). Values are never HTML (G3).
- The design behind the reference lives in the plugin, outside YayMatik versions: editing it changes **live** workflows at once, unlike core Send email whose message is part of the versioned config (design C8). Accepted for v1 (Q3): the panel under the button says "Design changes apply to live emails at once."
- Deleting a workflow or step does not delete the plugin's template (waiting runs and old versions may still point at it). `\YayMatik\Api\Api::references( step_id, field ): array` lists every value still used by any version, so the plugin can clean up its own leftovers. (Q8, agreed)

## 6. Data items and data fields (conditions + merge tags)

In YayMatik a **condition** is a rule on a data field (one core condition step, `condition`; GraphSchema allows no other condition step). So "add a condition" = add a field with `conditional` on. No new condition step kinds in v1.

### 6.1 Fields on a core data item
```php
$m->data_fields( 'order', [
    'yaycurrency_rate' => [
        'label' => __( 'Exchange rate used', 'yaycurrency' ),
        'type'  => 'number',
        'value' => [ $this, 'order_rate' ], // fn( WC_Order $order ): float
    ],
] );
```
Field keys start with the module id + `_`. Keys: `label`, `type` (§4.2), `value` (required), `display` `fn( $object, bool $html ): string` (HTML is kses'd), `options` `fn(): array` (enum / list), `help`, `timestamp`, `merge_tag` (default true), `conditional` (default true), `block` (email block, HTML, kses'd), `sample` (preview value).

What `value` receives per core item (**public object types**):

| Item | Object | Note |
|---|---|---|
| `order` | `WC_Order` | |
| `order_item` | `WC_Order_Item_Product` | |
| `refund` | `WC_Order_Refund` | |
| `review` | `WP_Comment` | |
| `customer` | **`CustomerView`**: `email()`, `user_id()` (0 for a guest), `first_name()`, `last_name()`, `order_id()` (0 unless from an order) | Today the object is a `WC_Order`, `WC_Customer`, internal `CartCustomer` or a reviewer depending on the trigger (RefResolver.php:65–79): not safe to expose |
| `cart` | **`CartView`**: `id()`, `email()`, `user_id()`, `currency()`, `total()`, `items()` (product_id, variation_id, quantity, line_total) | Today internal `SavedCart` |
| `site` | none (`value` gets null) | |

### 6.2 New data item
```php
$m->data_item( 'yaywholesale_request', [
    'label' => …, 'description' => …, 'icon' => …,
    'load'   => fn( string $id ): ?object,   // null = gone (step Skipped "… no longer exists.")
    'fields' => [ … same keys as 6.1 … ],
    'sample' => [ field => value ],
] );
```
A trigger provides it with the payload key `yaywholesale_request_id` (§5.3); an action reads it with `$run->item( 'yaywholesale_request' )`.

### 6.3 Not in v1
Derived items (`derives_from`), REST-searched option lists (`search`: product / category / coupon stay core-only; use `options`), meta-type fields.

## 7. Templates (J8)

```php
$m->template( 'yaymail_order_paid_email', [
    'name'        => __( 'Thank-you email designed in YayMail', 'yaymail' ),
    'description' => __( '…', 'yaymail' ),
    'category'    => 'post_purchase',            // core template categories: recover_carts, post_purchase, customer_lifecycle, alerts
    'workflow'    => 'yaymail_thank_you',        // optional, default the template id; groups versions ("Other versions")
    'created'     => '2026-12-01',               // "Newest" sort
    'before'      => [ __( 'Design the email in YayMail after you add the template.', 'yaymail' ) ], // "Before you start" lines
    'steps'       => [
        [ 'id' => 'trigger', 'step' => 'order_paid' ],
        [ 'id' => 'check', 'step' => 'condition', 'config' => [ 'match' => 'all', 'rules' => [
            [ 'field' => 'customer.unsubscribed', 'operator' => 'is_false' ] ] ],
          'then' => [
            [ 'id' => 'email', 'step' => 'yaymail_send_email', 'config' => [ 'to' => '{{ customer.email }}', 'subject' => __( 'Thanks for your order, {{ customer.first_name | default: "there" }}', 'yaymail' ) ] ],
          ],
        ],
    ],
] );
```

- **Chain format**, not nodes / edges / positions: core builds the graph and lays it out (as TS `flow.ts` does for core templates). A condition takes `then` / `otherwise`.
- A template may use **core public steps (§12)** and **its own module's steps** only. Templates that combine two plugins belong to YayMatik Pro (design P2).
- Ready content (subject / body) is translated by the plugin with its own text domain (J8). Store-specific values are never baked in at registration (proposal finding 5): use a variable, or leave the field blank where core fills it at run time (high-value alert: blank recipients = the admin email).
- Core checks each template **on the server** with the same checks as Use template / Activate (`GraphSchema::prepare` + `Validator::errors`). A template is hidden + diagnostic (under `WP_DEBUG` also `_doing_it_wrong()`) when `prepare` fails or it has a structural issue: no trigger, no action, an unlinked or unknown step, missing data, an unknown variable. A step that still needs setup (`setup_issue`, cart tracking off, a required field the template leaves empty) keeps it shown: Use template creates the draft, and Activate is blocked until the step is set up.
- Browse templates shows each module's templates in **its own group with the plugin's name and logo** (`pluginsOf`, plugins.ts:6). Templates of an inactive plugin are not shown.

## 8. Privacy

- Emails written through the run stores are indexed automatically (033 `run_emails`): export / erase find them. **[Certain]**
- Runs whose `subject` uses a core shape are found already (`PersonSubjects::for_email()`).
- Other subjects: module key `'privacy_subjects' => fn( string $email ): string[]` — the plugin's subjects about that person (e.g. `yaywholesale_request:7`). Called by export and erase. Only subjects starting with the module id + `_` are kept (core finds its own shapes itself); a throw gives none and a diagnostic.

## 9. Error isolation and diagnostics

| Where | Today | v1 |
|---|---|---|
| Module / step / data item construction | `new $class()` unguarded (ModuleRegistry.php:55,90,146) | Declarations validated at registration; a bad one is refused, the rest of the module loads |
| `yaymatik_fire` handler | — (new; replaces a core-side `listen`) | Never throws into the plugin's request: a payload it can't map (unknown trigger, missing `provides`, bad keys) → no run or keys dropped + diagnostic |
| `matches` | inside `dispatch()` try (Dispatcher.php:196–239) | try/catch per workflow: a throw → that workflow doesn't run + diagnostic naming the module |
| `run`, `validate`, `build`, `setup_issue`, option callables | Executor catches Throwable (Executor.php:284–295) | same; Failed with the reason; `build` also in Preview; a `run` that returns no `done` / `skip` / `fail` → Failed "The step returned no result." |
| Catalog / meta for the editor | unguarded | per step and per data item: a throw leaves that one out (editor still opens) + diagnostic |
| Data field `value` / `display` / `options` | unguarded in Template / Criteria | throw → empty value (a condition reads "missing") + diagnostic |
| Fatal errors (E_ERROR, parse) | — | Not catchable in PHP; WordPress recovery mode handles them |

- **Diagnostics:** option `yaymatik_extension_errors`, last 50 entries (module, callback, message, time, count); one write per (module, callback) per 10 minutes; also `Logger::error`. Shown in **Health check › Extensions**: each module, its `requires_api`, errors in the last 24 h. No auto-disable beyond the existing run ceiling (Dispatcher.php:161–170). (Q12, agreed)

## 10. Plugin deactivated or removed (J9)

When a plugin is off, its code doesn't run, so core can't ask it for a step's name. Core keeps a small **step index** (option `yaymatik_extension_steps`: step id ⇒ module id, module label, kind, step label, output keys), refreshed whenever the registry is built and its fingerprint changed. (Q1, agreed)

**The rule in one line (Q2, agreed; also in design §9, the decision log and the UI text):** *a workflow that uses a step from an inactive plugin can be opened, edited and saved, but not activated or updated; if it is already active it keeps running and skips that step with a reason.* Why: turning on a workflow we already know will skip its main step (often the email) misleads the store owner; stopping a running workflow on deactivation would cancel customers' waiting runs over a plugin update that takes a minute.

| Place | Behavior |
|---|---|
| Editor | Card greyed: "Needs YayMail — the plugin isn't active." Config is kept as stored, read-only; the step can be deleted. Later merge tags reading its outputs show as "from a step whose plugin is off" (not an error) |
| Save | Allowed. `GraphSchema::prepare` keeps a node with an unknown step **if the stored draft / live graph already has it**, with its saved config (read-only: what the editor sends for it is ignored); a *new* unknown step is still refused (GraphSchema.php:50–53) |
| Activate / Update | Blocked with issue `missing_plugin`: "This workflow uses YayMail, which isn't active. Activate YayMail to turn the workflow on." |
| Already-active workflow | Keeps running. A run reaching the step: **Skipped** with the reason "YayMail isn't active." and goes on (today Failed, Executor.php:280–283). A missing **trigger** fires nothing. A step a plugin dropped while it stays active is not "inactive": it fails as an unknown step |
| Health check | New check `missing_plugin`: active workflows with steps from inactive plugins (count, workflow links, Plugins page link; no auto-fix). Fills design §9 row "step from a missing plugin" |
| Plugin re-activated | Everything works again; nothing was rewritten |

## 11. Hooks shipped in 1.0 (proposal N6)

| Hook | v1 status | Why |
|---|---|---|
| `yaymatik_register` | **public (new)** | The entry point |
| `yaymatik_fire` | **public (new)** | Starts an extension trigger (§5.3) |
| `yaymatik_resendable_emails` (filter) | public, stable | Tiny, already useful to WooCommerce-email plugins |
| `yaymatik_change_role_allow_all` (filter) | public, stable | Tiny, site-owner switch |
| `yaymatik_modules` | internal (Pro uses it) | Class-based; extensions use the registrar |
| `yaymatik_data_item_{id}_fields`, `yaymatik_trigger_provides`, `yaymatik_trigger_refs` | internal | Replaced by `data_fields` / `data_item` / the `yaymatik_fire` payload; core implements those on top of them |
| `yaymatik_cart_abandoned`, `yaymatik_customer_inactive` (actions) | experimental, not in this contract | Payload may change; use the core triggers |

## 12. Core steps usable by extension templates (G4)

Public and semver'd: the **ids, config keys and value shapes** below, the **condition operator ids** (Operators.php:12–17) and the **core data item / field keys** (catalog). The full list is generated from the catalog into [extension-api-core.md](extension-api-core.md) (`./run.sh extension-core`); a PHPUnit snapshot (`tests/Unit/Api/CoreStepsTest.php`) fails when any of them changes, and `SurfaceTest` does the same for the public classes in `includes/Api/`.

| Kind | Id | Config keys |
|---|---|---|
| Trigger | `order_created`, `order_paid`, `order_refunded`, `cart_abandoned`, `checkout_abandoned`, `customer_account_created`, `review_posted` | none |
| Trigger | `order_status_changed` | `from` (status[]), `to` (status[]), `only_checkout` (bool) |
| Trigger | `order_note_added` | `note_type`, `contains`, `skip_yaymatik` |
| Trigger | `customer_inactive` / `customer_order_count_reaches` / `customer_total_spend_reaches` | `days` / `count` / `amount` |
| Condition | `condition` | `match` (`all`\|`any`), `rules[]` of `{ field, operator, value, valueType?, unit?, key? }` or a group `{ match, rules }` one level deep |
| Action | `send_email` | `to`, `subject`, `heading`, `message` (HTML, merge tags) |
| Action | `send_internal_email` | `recipients`, `subject`, `message` |
| Action | `wait` | `delay: { value, unit }` (minutes \| hours \| days \| weeks) |
| Action | `wait_until` | `time`, `weekdays` |
| Action | `generate_coupon` | `discount_type`, `amount`, `expiry_days`, `minimum_spend`, `individual_use`, `limit_to_customer`, `code_prefix`; outputs `code`, `amount`, `expires_at`, `box` |
| Action | `add_order_note`, `change_order_status`, `resend_wc_email`, `update_order_meta`, `update_customer_meta`, `change_customer_role` | as in the generated list |

Core categories: triggers `order`, `cart`, `customer`, `review`; actions `email`, `order`, `coupon`, `customer`, `flow`.

## 13. Keep / fix / add / drop (vs develop 6be688a)

| | Item | Gain | Cost |
|---|---|---|---|
| Keep | Server-driven fields (no JS for forms) | Extensions need no build step | Complex forms need the iframe |
| Keep | Module = plugin, logo as one-colour SVG mask | Picker / template groups by plugin | — |
| Keep | `StepResult` statuses, Executor's per-step catch, active-trigger listening, first-owner ids | Proven in 1.0 | — |
| Keep | Refs-only scope, JSON-safe args, loop guard, run ceiling | Same runtime rules for everyone | — |
| Fix | Inheritance contract → arrays, one `do_action`, one `Run` object; core adapters | Core refactors never fatal an extension; a plugin needs no YayMatik class but `Run` | Adapter layer to maintain; less freedom than subclasses |
| Fix | Trigger callbacks (`listen`, `refs`, `subject`) → `yaymatik_fire` with a payload | The plugin fires where the event happens; subject and privacy come from core shapes | Payload keys are a convention to document; module data items need `privacy_subjects` |
| Fix | No error isolation outside `run` (N1) | One bad plugin can't take the site / editor down | try/catch at ~8 call sites, a diagnostics store |
| Fix | Unknown step = save error / Failed (N4, V12.2) | J9 | Step index option; Validator + Executor + editor changes |
| Fix | Two type vocabularies (finding 3) | One set of type names (strings) in the public API | Mapping names → core types and condition types inside core |
| Fix | Closed categories (N5) | Extensions add picker sections | TS types open up |
| Fix | Hard-coded step ids in EmailPreview, run detail, "sends customer email" (EmailPreview.php:113,144; runResults.ts:65; meta.ts:30) | Email extensions behave like Send email | Core email code reads capabilities instead of ids |
| Fix | Polymorphic / internal objects for `customer`, `cart` (new N8) | Stable callback argument | Two small view facades |
| Add | API version + `supports()` + `requires_api` | Plugins work across MINOR releases | Discipline on every release |
| Add | Email capability: Preview, Send test, delivery (G1, G2) | Unsubscribe / List-Unsubscribe compliance, "Sent to", same UI | Unsubscribe placement in full-document HTML (Q6) |
| Add | Iframe editor + postMessage (G3) | Drag-and-drop designers inside the workflow | Security surface; design edits outside versioning (Q3) |
| Add | PHP templates in chain format (J8) | Plugins ship ready workflows | Server-side validation, meta size grows |
| Add | Trigger `facts` (narrow); `privacy_subjects` | Event-time data without a plugin table | Validator / Insert variable / run detail learn trigger facts; no DB change |
| Drop | Core `dedupe` for triggers (Q5) | No DB_VERSION 9, no `trigger_claims` expiry column | Plugins dedupe before `yaymatik_fire` (§5.3 pattern) |
| Add | Health check › Extensions + `missing_plugin` | Site owner sees what broke | — |
| Add | Example plugin `dev/example-extension/` + contract tests + surface snapshot (J7) | Breaking change caught in CI | Snapshot updates on purpose only |
| Drop | Public `yaymatik_modules` / provides / refs filters for extensions | One way to integrate | Pro keeps them internally |
| Drop (v1) | Derived data items, Abilities bridge, JS panels in the editor, cross-origin iframes, extension condition steps | Smaller frozen surface | Later MINOR if asked |

## 14. Check against the four Yay plugins (J5)

Facts from each plugin's WordPress.org page (Nov 12). Connector code is each owner's task (J7).

| Plugin | Need | Contract piece | Gap / note |
|---|---|---|---|
| **YayMail** (WC email customizer, 50k+, templates tied to WC email types) | Action "Send email designed in YayMail": Preview, Send test, Customize design | `action` + `email` (`wrap: false`) + `editor` (`field: template_id`) | YayMail has no standalone "workflow email" template today: the connector must add a template kind not bound to a WC email, an embedded editor mode, and `render( template, order\|sample )`. It already has an AutomateWoo addon — reuse that pattern. **[Guess]** on its internals |
| | Template "Order paid → condition → YayMail email" | `template` with `order_paid` + `condition` + `yaymail_send_email` | First Preview before design: the step needs a default template so a new workflow previews something (connector) |
| **YayMagic** (pretty email builder; templates with or without an order; blocks render content or shortcodes from the chosen template) | "Send email designed in YayMagic" on any trigger: account created, inactive, milestones, review; order triggers when the design has order blocks | `action` + `email` + `editor`; `validate( $config, $available )` refuses order blocks without `order`; core renders YayMatik merge tags (Q4b); Preview of customer-only triggers uses a real customer (Q4a) | **Second golden consumer, the no-order path** (YayMail = order path). Connector: embedded editor mode, render by template id + `$run->order()` / `$run->user()`, shortcodes that don't read globals (`build` runs in a background job) |
| **YayCurrency** (switcher, choice kept in a cookie, Pro checkout in chosen currency, rate stored per order) | Currency data on orders / customers for conditions + emails | `data_fields( 'order', yaycurrency_rate, yaycurrency_total_default … )`; `order.currency` is core already (OrderData.php:140) | A customer's *current* currency is a cookie: not readable in a background run → only as a trigger fact |
| | Trigger "customer changed currency" — **not a real need; only a test of how easy a third-party trigger is** (Q5) | `trigger` with `facts` (`from`, `to`), dedupe before `yaymatik_fire`, payload `user_id` (logged-in only) | Not built: the example plugin's "Customer earned points" trigger tests the same pieces (`facts`, dedupe before `yaymatik_fire`, payload `user_id`) |
| **YayPricing** (rules with generated id; cart discounts use the id as coupon code; applied rules shown on the order since 3.1) | Data / conditions on rules and discounts applied to an order | `data_fields( 'order', yaypricing_rules (list, options = rule names), yaypricing_discount_total (money) )`; `data_fields( 'order_item', yaypricing_rule … )` | Covered. Where it stores applied rules on the order is not documented **[Guess]**; a deleted rule shows its id |
| **YayWholesale** (roles, one role per wholesaler, request → approve / reject, min qty / amount; no documented hooks) | Data on wholesale role, prices, minimums | `data_fields( 'customer', yaywholesale_role (enum), yaywholesale_status, is … )` via `CustomerView::user_id()`; `data_fields( 'cart', yaywholesale_missing_amount (money) … )` via `CartView`; order fields `yaywholesale_role` | Needs the view facades (N8). "Add $X more to unlock wholesale prices" for abandoned carts works with cart fields |
| | Trigger "customer approved as wholesaler" | `trigger` provides `customer`, fact `role`; fired with `do_action( 'yaymatik_fire', 'yaywholesale_approved', [ 'user_id' => …, 'role' => … ] )` | YayWholesale must add that line where it approves (connector) **[Certain]** no hook documented |

Result: every listed need maps to a contract piece; the gaps are on the plugins' side (embedded editors, render function, missing hooks) except **N8**, which is core's.

## 15. Decisions (all agreed, Nov 13)

| # | Decision |
|---|---|
| Q1 | Step index option `yaymatik_extension_steps` (no graph shape change) |
| Q2 | Missing plugin: open / edit / save yes; Activate / Update blocked (`missing_plugin`); already active keeps running, step Skipped with a reason. Written in §10, design §9, the decision log and the UI text |
| Q3 | Iframe design edits apply to live workflows at once; the panel says so |
| Q4 | Emails need not be about an order. YayMail = order path, YayMagic = no-order path; both golden consumers |
| Q4a | Preview of a customer-only trigger uses the newest real customer account (core Send email too) |
| Q4b | Core renders YayMatik merge tags in extension email subject / HTML; postMessage `init` carries `variables` |
| Q5 | No core `dedupe` (plugins dedupe before `yaymatik_fire`); `facts` kept, narrow (§5.3) |
| Q6 | Plugin places `unsubscribe_url()`; core inserts its footer before `</body>` when missing |
| Q7 | No kses on extension email HTML; kses on values core inserts into other emails |
| Q8 | No delete hook; `Api::references()` for the plugin's own cleanup |
| Q9 | Module id + `_` prefix enforced for every id |
| Q10 | Public facades, no `class_alias` |
| Q11 | No Abilities bridge; open categories; Health check › Extensions |
| Q12 | No auto-disable; diagnostics only |
| N8 | `CustomerView` / `CartView` for data-field callbacks on `customer` / `cart` |
| Split | Two PRs (§17) |

## 16. Changelog
- 1.0.0 (YayMatik 1.1): first stable version.

## 17. Checkpoints and PRs

One plan (`.claude/plans/dev/034-extension-api.md`), two PRs. PR A `jack/feature/034a-extension-api` from develop 6be688a; PR B `jack/feature/034b-extension-ui` from PR A's last commit, opened after PR A is merged. Risk 1 = docs / small, 2 = new code behind the API, 3 = touches save / run / email paths every workflow uses.

| PR | CP | Content | Risk |
|---|---|---|---|
| A | CP0 | This contract committed as `docs/extension-api.md`; design §5 / §8 / §9 / §10 (P4 → 1.1), decision log rows (incl. the Q2 rule), ROADMAP V12.2 / P4 | 1 |
| A | CP1 | Error isolation (§9 table) + diagnostics option + Health check › Extensions | 2 |
| A | CP2 | Registrar, `Module` handle (inert on refusal), id rules, API version / `supports()` / `requires_api`, field / type arrays, `yaymatik_fire` handler (payload → data, subject, facts; same Dispatcher path), `Run` (`done` / `skip` / `fail`), trigger + action adapters (`matches`, `once_per`, `run`, `validate( $config, $available )`, `outputs`, `summary`), open categories (PHP + TS) | 2 |
| A | CP3 | Data items / `data_fields` with type names, `CustomerView` / `CartView`, kses on display / block HTML, trigger `facts` in Scope / `Run::get()` / Validator / Insert variable / conditions / run detail | 2 |
| A | CP4 | Missing plugin (§10): step index, GraphSchema keeps stored unknown nodes, Validator `missing_plugin`, Executor Skipped, editor card, Health check `missing_plugin` | 3 |
| A | CP5 | `privacy_subjects`; contract tests: public-surface snapshot, core-steps snapshot (G4) + generated `docs/extension-api-core.md`; `034a-pr.md` | 2 |
| B | CP6 | Email capability (G1 / G2 / G5): `build( $config, $run )`, `Run::unsubscribe_url()` / `is_test()`; EmailPreview + delivery service read capabilities, not step ids; customer sample (Q4a); merge tags in extension HTML (Q4b); render order; "sends customer email" by capability (meta.ts:30); run detail `result` (runResults.ts) | 3 |
| B | CP7 | Templates (J8): chain format → graph + layout, server validation, meta → TS registry, plugin group + logo, "Before you start" lines | 2 |
| B | CP8 | Iframe editor (G3): modal, postMessage v1 with `variables`, origin / source / channel checks, same-origin check of the built URL, save → config + validate, live-design notice, `Api::references()` | 3 |
| B | CP9 | Example plugin `dev/example-extension/` (every feature; written as a class with named methods; a points trigger with `facts` + dedupe before `yaymatik_fire`, a "Reward redeemed" trigger on its own data item with `once_per`, `privacy_subjects`, a `setup_issue`; a no-order email action with an iframe designer; a template); `./run.sh check-extension` (wp-env): register, run, deactivate → open / save / Skipped / Activate blocked, reactivate; measures for the PR (lines, concepts) | 2 |
| B | CP10 | `.pot` last, docs pass, `034b-pr.md` | 1 |
