# Plan: Email stack — YayMail, YayMagic, YaySMTP work together safely

## Goal
Core's side of the email stack, and a test per cell of its matrix proving one email step sends one email, wrapped once, logged once, with any mix of YayMail, YayMagic and YaySMTP active or missing.

## Approach
Words and matrix as in `docs/extensions-roadmap.md` (email stack). Builds on `extension-scaffolds.md`.

- **Mark (D20).** `EmailDelivery` adds `X-YayMatik-Run: <run id>-<step id>` to every email it sends (`test` for Send test, none for Preview). One place, so core Send email, Send internal email and every extension's email action carry it.
- **Delivery status (D19).** Core listens to `wp_mail_succeeded` / `wp_mail_failed` for marked mail and records sent / failed on the step. A new public action `yaymatik_email_status( $mark, $status )` lets YaySMTP report delivered, bounced, opened, clicked later. Run detail shows the latest status under "Sent to …". Stored per step output; nothing runs on the shopper's request.
- **Fake adapters** of the three scaffolds gain the products' email behavior: YayMail wraps WooCommerce emails unless marked, YayMagic wraps every `wp_mail` unless marked, YaySMTP logs by mark and calls `yaymatik_email_status`.
- **Cell tests** in `tests/Unit/Ecosystem/`, one per cell, on the fake adapters. Each checks one email, one wrapper, unsubscribe link, run detail.
- **Local check (D21).** `./run.sh check-ecosystem [--wp-env|--wp-env-min]`, in the pattern of `check-extension.sh`: runs the same cells on a real site with a mail log, with an installed product in place of its scaffold; prints PASS / FAIL per cell and which ran on a scaffold; joins `check-all`.

## Checkpoints
1. Mark and delivery status — Risk: 3 — every email carries the mark; sent / failed recorded; `yaymatik_email_status` updates run detail.
2. Fake adapter email behavior, cell tests, local check — Risk: 2 — every cell passes in `pnpm test:php` and `./run.sh check-ecosystem --wp-env`.

## Out of Scope
- Template 8 (needs tags, D16) and template 21 (D7).
- Open / click reporting UI beyond the status line.
- PHPStan stubs and `wp yaymatik extension check` (D13). Cross-repo CI (D21).

## Decisions
Confirmed: D9, D17, D19, D20, D21, D22.

## Meta
- Target branch: feature/ecosystem-email-stack
- Base branch: feature/lane-a
- Unattended: yes
