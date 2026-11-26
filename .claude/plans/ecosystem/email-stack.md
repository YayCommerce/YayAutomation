# Plan: Email stack — YayMail, YayMagic, YaySMTP work together safely

## Goal
Core's side of the email stack, and tests that prove one email step sends one email, wrapped once, logged once, with any mix of YayMail, YayMagic and YaySMTP active or missing.

## Approach
Roadmap: `docs/extensions-roadmap.md` (group 1, catalog 1–7 and 21). Built on the recommendations below; revisit them before building.

- **Mark (D20).** `EmailDelivery` adds `X-YayMatik-Run: <run id>-<step id>` to every email it sends (`test` for Send test, none for Preview). One place, so core Send email, Send internal email and every extension email action carry it.
- **Delivery status (D19).** Core listens to `wp_mail_succeeded` / `wp_mail_failed` for marked mail and records sent / failed on the step. A new public action `yaymatik_email_status( $mark, $status )` lets YaySMTP report delivered, bounced, opened, clicked later. Run detail shows the latest status under "Sent to …". Statuses stored per step output; nothing runs on the shopper's request.
- **Scaffolds** in `dev/ecosystem/yaymail/`, `yaymagic/`, `yaysmtp/`: each a module on `yaymatik_register`, public API only, with its steps, fields, triggers and templates 1–7 in the chain format of extension-api.md §7. Step bodies call a small product adapter (render a design by id, report a log change). These folders are what `copy-scaffolds.md` copies; `yaymagic-scaffold.md` then turns YayMagic's into the showcase of an extension's own UI.
- **Loading.** YayMatik loads `dev/ecosystem/*/` only with `YAYMATIK_IS_DEVELOPMENT` on, on `plugins_loaded` priority 20 as a later plugin would; `YAYMATIK_DEV_ECOSYSTEM_OFF` lists products to leave out (the "plugin missing" cells); a stand-in is skipped when its real plugin is active. No symlink or wp-env mapping (E4).
- **Fake adapters** in `tests/Support/Ecosystem/` that act like each plugin: YayMail wraps WooCommerce emails unless marked, YayMagic wraps every `wp_mail` unless marked, YaySMTP logs by mark and calls `yaymatik_email_status`.
- **Scenario tests** in `tests/Unit/Ecosystem/`: one per cell of the group 1 matrix (design source × YaySMTP on/off × other designer on/off × designer removed after save), on the fake adapters. Each checks one email, one wrapper, unsubscribe link, run detail.
- **Local check (D21).** `./run.sh check-ecosystem [--wp-env|--wp-env-min]`, in the `check-*` pattern of `check-extension.sh`: runs the same cells on a real site with a mail log, using each product's real plugin when it is installed and active, else its scaffold; prints PASS / FAIL / "scaffold" per cell; joins `check-all`. wp-env maps `dev/ecosystem/*` like `dev/example-extension`. No cross-repo CI.

## Checkpoints
1. Mark and delivery status — Risk: 3 — every email carries the mark; sent / failed recorded; `yaymatik_email_status` updates run detail. Test: Send test and a real run, then run detail.
2. Scaffolds, fake adapters, templates 1–7, scenario tests, local check — Risk: 2 — the matrix passes in `pnpm test:php` and `./run.sh check-ecosystem --wp-env`.

## Out of Scope
- Copying to the product repos (`copy-scaffolds.md`); real adapters (product teams).
- Cross-repo CI.
- Template 8 (needs tags, D16) and template 21 (D7).
- Open / click reporting UI beyond the status line.
- PHPStan stubs and `wp yaymatik extension check` (D13).

## Decisions
Recommended, revisit before building: D19 (status hook + WordPress mail hooks), D20 (header mark). Confirmed: D9, D17, D21 (local check, no cross-repo CI), D22.

## Meta
- Target branch: feature/ecosystem-email-stack
- Base branch: feature/lane-a
- Unattended: yes
