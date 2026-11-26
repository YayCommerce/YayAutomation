# Plan: Email stack — YayMail, YayMagic, YaySMTP work together safely

## Goal
Core's side of the email stack, and tests that prove one email step sends one email, wrapped once, logged once, with any mix of YayMail, YayMagic and YaySMTP active or missing.

## Approach
Roadmap: `docs/extensions-roadmap.md` (group 1, catalog 1–7 and 21). Built on the recommendations below; revisit them before building.

- **Mark (D20).** `EmailDelivery` adds `X-YayMatik-Run: <run id>-<step id>` to every email it sends (`test` for Send test, none for Preview). One place, so core Send email, Send internal email and every extension email action carry it.
- **Delivery status (D19).** Core listens to `wp_mail_succeeded` / `wp_mail_failed` for marked mail and records sent / failed on the step. A new public action `yaymatik_email_status( $mark, $status )` lets YaySMTP report delivered, bounced, opened, clicked later. Run detail shows the latest status under "Sent to …". Statuses stored per step output; nothing runs on the shopper's request.
- **Fixture extensions** in `tests/Support/Ecosystem/`: YayMail (order-path design, wraps WooCommerce emails unless marked), YayMagic (no-order design, wraps every `wp_mail` unless marked), YaySMTP (logs by mark, calls `yaymatik_email_status`, fires its "delivery failed" trigger). They register through the public API only, as the real extensions will.
- **Templates 1–7** declared by the fixtures, in the chain format of extension-api.md §7, so the scaffold row copies them as they are. Template 21 waits for D7.
- **Scenario tests** in `tests/Unit/Ecosystem/`: one per cell of the group 1 matrix (design source × YaySMTP on/off × other designer on/off × designer removed after save). Each checks one email, one wrapper, unsubscribe link, run detail.
- **CI matrix (D21).** A job that, per product, checks out its repo with a read-only token when it has an extension and runs the scenario suite with it in place of the fixture. CI is disabled until December: build it, enable with CI.

## Checkpoints
1. Mark and delivery status — Risk: 3 — every email carries the mark; sent / failed recorded; `yaymatik_email_status` updates run detail. Test: Send test and a real run, then run detail.
2. Fixtures, templates 1–7, scenario tests — Risk: 2 — the matrix passes in `pnpm test:php`.
3. CI matrix job — Risk: 2 — the job runs fixtures today and a product repo once its extension exists.

## Out of Scope
- Real extensions in the product repos (`extension-scaffolds.md`, then product teams).
- Template 8 (needs tags, D16) and template 21 (D7).
- Open / click reporting UI beyond the status line.
- PHPStan stubs and `wp yaymatik extension check` (D13).

## Decisions
Recommended, revisit before building: D19 (status hook + WordPress mail hooks), D20 (header mark), D21 (matrix job in YayMatik CI). Confirmed: D9, D17, D22.

## Meta
- Target branch: feature/ecosystem-email-stack
- Base branch: feature/lane-a
