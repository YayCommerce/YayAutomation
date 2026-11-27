# Plan: Email stack — YayMail, YayMagic, YaySMTP work together safely

## Goal
Core's side of the email stack, and a test per cell of its matrix proving one email step sends one email, wrapped once, logged once, with any mix of YayMail, YayMagic and YaySMTP active or missing.

## Approach
Words and matrix as in `docs/extensions-roadmap.md` (email stack). Builds on `extension-scaffolds.md`.

- **Mark (D20, D28).** `EmailDelivery` adds `X-YayMatik-Mark: <run step id>` (the run-step row, so each loop item of a later For each gets its own) to every email it sends (`test` for Send test, none for Preview). One place, so core Send email, Send internal email and every extension's email action carry it.
- **Delivery status (D19).** Core listens to `wp_mail_succeeded` / `wp_mail_failed` for marked mail and records sent / failed on the step. A new public action `yaymatik_email_status( $mark, $status )` lets YaySMTP report delivered, bounced, opened, clicked later. Stored per step as `output.delivery` `{ status, at, reason? }`, the furthest stage only; nothing runs on the shopper's request.
- **Run detail status line (screen draft).** Branch `feature/ecosystem-email-stack` holds the draft: `DeliveryStatus.tsx` under the step's "Sent to …" in `RunDetailSheet.tsx`, data from `runDelivery.fixtures.ts`, opened with `?run=draft-delivery` on any workflow's overview. Looks like: status line → the step's result line in run detail. The header becomes one row: the subject and the status badge in a fixed 54px row (`h-13.5 py-0`, the app header's height), the title on the kit's line height, the Started · Finished times dropped (each step's row carries its time). Delivered, opened, clicked: muted with a green dot; bounced: red, its reason in a `HelpTip`. Sent and failed show no line ("Sent to …" and the failed step already say it). One status per step, even for several recipients. The Builder continues from that branch: merges `feature/lane-a` first, feeds `output.delivery` from the server, deletes the fixtures file and the draft branch in `RunDetailSheet`, and adds a `RunDetailSheet` test per state.
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
Confirmed: D9, D17, D19, D20, D21, D22, D28.
- P2.1 Run detail status line and one-row header as in the screen draft — confirmed
- B1 Mark = the run-step row id, kept in `Scope` (not stored); a status reported while the step runs is held in memory and written by the Executor with the step's output — confirmed (auto)
- B2 Core's own sent / failed never replaces a status a mail plugin reported first in the same send — confirmed (auto)
- B3 `failed` shows in red under a completed step (a mail plugin's later report); hidden on a failed step — confirmed (auto)
- B4 Statuses are ranked: a report replaces only an earlier stage (sent < delivered < opened < clicked); failed and bounced are final — confirmed (Review, was latest-wins)
- B5 `check-ecosystem` sends no email: it replaces the mailer with a mail log that fires `wp_mail_succeeded`; "logged once" with a real YaySMTP = one `yaymatik_email_status` report per mark — confirmed (auto)

## Meta
- Target branch: feature/ecosystem-email-stack
- Base branch: feature/lane-a
- Unattended: yes
- Draft: confirmed
