# Ecosystem scaffolds

Six extensions as YayMatik builds them before any product code exists: YayMail, YayMagic, YaySMTP, YayWholesale, YayLoyalty, YayPricing (catalog in `docs/extensions-roadmap.md`, templates 1–18 but 8). Never shipped: `/dev` is not in the zip.

Each folder is one product:

- `<Product>_YayMatik.php`: the declarations (module, triggers, actions, data, templates) on the public Extension API (`docs/extension-api.md`). Copied to the product as it is.
- `<Product>_YayMatik_Fake_Adapter.php`: every callback the declarations name. Sample values and the cheapest real thing (user meta for a role or points). The product team replaces it with the real adapter.
- `load.php`: hooks the declarations, on the fake adapter, to `yaymatik_register`.

## Loading

With `define( 'YAYMATIK_IS_DEVELOPMENT', true );` in `wp-config.php`, YayMatik loads every `dev/ecosystem/*/load.php` on `plugins_loaded` priority 20, as a plugin loaded after it would (`YayMatik::load_dev_ecosystem()`).

To leave products out, list their folders:

```php
define( 'YAYMATIK_DEV_ECOSYSTEM_OFF', 'yaymail,yaysmtp' );
```

A product whose own build carries its extension must be in that list: both declare `<Product>_YayMatik`, and PHP stops at the second.

Templates 5, 6 and 16 start on Pro triggers, so they show only with YayMatik Pro.

## Firing a trigger

```bash
./run.sh ecosystem-fire <trigger> [--wp-env]
```

Fires the trigger through `yaymatik_fire` with a sample payload (its `sample_facts`, the newest customer, id `1` for a scaffold's own data item), runs the runs it starts and lists each step's status. A workflow on that trigger must be active: use one of its templates and activate it. On wp-env each wp-cli call defines `YAYMATIK_IS_DEVELOPMENT` itself; its admin pages don't, so they show no scaffold templates, and the workflow there is made from wp-cli (as check scripts do).

## Email behavior

The fake adapters of YayMail, YayMagic and YaySMTP also act as the products do on every email (`hook_mail()`, from `load.php`), so the email stack can be checked before the products change:

- YayMail wraps WooCommerce's emails in its design, YayMagic wraps every `wp_mail()`, except an email with the `X-YayMatik-Mark` header.
- YaySMTP logs every email it sends (option `yaysmtp_fake_sent_log`) and calls `yaymatik_email_status` with the mark and `delivered`.

`tests/Unit/Ecosystem/EmailStackTest.php` runs each cell of the email stack's matrix on them. `./run.sh check-ecosystem [--wp-env|--wp-env-min]` runs the same cells on a real site, an installed `yaymail`, `yaymagic` or `yaysmtp` plugin in place of its scaffold, and prints which ran on a scaffold. It sends no email: a mail log stands in for the mailer and fires `wp_mail_succeeded`, so an installed YaySMTP that logs from its own mailer (`phpmailer_init`) reports nothing there; its cells need it to report from `wp_mail_succeeded`. Installed products are left active or not as they were.

## Rule for adapters

An action with a side effect skips it when `$step->is_test()` is true (`set_role`, `award_points`). The real adapter keeps that check.
