# Workflow page UI Roadmap — YayMatik v1.0

**Release date:** estimated after M1 · **Status:** Planning · **Technical plan:** [ROADMAP-dev.md](ROADMAP-dev.md) · [workflow-core-design.md](workflow-core-design.md)

## Goal

This roadmap describes the Workflow pages for v1.0, page by page: what each page has today, what v1.0 adds, and in which milestone. v1.0 ships 8 ready-to-use workflows as 18 templates. A store owner can use any template, activate it and get results with no setup beyond reviewing the email text. The 5 abandoned cart and checkout templates also need cart tracking turned on in Settings; the template preview and editor point to it.

Wording and page layouts follow [plan-identity.md](plan-identity.md) (Activate / Deactivate, Update, Use template, Insert variable, Yes / No). "Today" lines describe the current UI with its current words.

We chose these 8 because they cover what merchants install an automation plugin for. Recovering abandoned carts and checkouts brings in the most revenue. Thank-you, cross-sell and review emails follow every completed order. Welcome and win-back emails cover the customer lifecycle. The high-value order alert gives the store team a quick heads-up on big orders. The other 6 workflows in our list have lower demand or legal risk, so they move to v1.1 / v1.2.

## UI by page

| Page | What changes in v1.0 | Milestone |
|---|---|---|
| Workflow list | Run counts, last run, status filter, row actions menu | M2, identity |
| Create workflow dialog | New layout (describe, browse templates, choose a trigger); new starting events in the trigger picker | identity, M4, M6, M8 |
| Template Library | 18 templates, category and plugin sidebar, cart tracking badge | M1, M8 |
| Template preview | Side panel: Steps, Plugins used, Other versions, Before you start | M1, M3, M8 |
| Editor | New triggers and steps: Wait, Generate coupon, Send email with Preview / Send test | M1, M2, M4, M6, M8 |
| Workflow overview | Summary row, workflow runs table and run detail | M2 |
| Settings | Cart tracking, Emails, Run history | M7 |
| Store front | Restore cart link, unsubscribe page | M1, M7 |
| Customer emails | What shoppers receive, per workflow | M1–M8 |

### Workflow list

**Today:** a table of workflows (name, status, trigger, runs, last run, last updated) with a status filter, a "⋯" row menu, bulk actions and an empty state. Last run shows how that run ended, linked to its detail.

**v1.0 adds:**
- Two columns: runs in the last 30 days, and when the workflow last ran (e.g. "2 hours ago"), with how that run ended under it ("Failed" in red), linked to that run. Shown with "—" since identity phase 1; filled in M2.
- Status filter: All · Active · Inactive · Draft. ✅ identity phase 1
- Row actions in a "⋯" menu: Edit, Rename, Activate / Deactivate, Delete. Edits not yet live show a "Draft changes" badge (moving a card is not an edit); a Draft or Inactive workflow that can't be activated yet shows "Needs setup". ✅ identity phase 1 *([identity](plan-identity.md))*

**States:** a workflow that never ran shows "—" in both columns.

**Done when:** counts match the run history on the overview page.

**Milestone:** M2

### Create workflow dialog

**Today:** the question "What do you want to automate?", a describe box with example prompts ("Coming soon"), and two start cards: Browse templates and Choose a trigger.

**v1.0 adds:**
- ✅ New layout, identity phase 1 *([identity](plan-identity.md))*: the question "What do you want to automate?", a describe box with example prompts (shows "Coming soon" until the AI ships in v2.0), then two start cards: **Browse templates** and **Choose a trigger**.
- The trigger picker lists the new starting events as they land: account created, inactive customer, abandoned cart and checkout.

**Done when:** both start cards work, the describe box is clearly unavailable, and each new trigger can be picked as soon as its milestone lands.

**Milestone:** identity (layout), M4, M6, M8 (triggers)

### Template Library

**Today:** 18 templates (Recover carts, Post-purchase, Customer lifecycle, Alerts), with search, sort, a sidebar (categories with counts, plugins), cards with a strip of step icons, and a "Needs cart tracking" badge while cart tracking is off.

**v1.0 adds:**
- 18 templates, arriving with their milestones.
- ✅ A sidebar, identity phase 1 *([identity](plan-identity.md))*: Categories with counts (All · Recover carts · Post-purchase · Customer lifecycle · Alerts), then Plugins with checkboxes, installed first. Long lists show "Show more"; chosen filters appear as removable chips above the grid.
- ✅ Cards open with a strip of the template's step icons, then name and description; plugins show as icons (up to 3, then "+N", name on hover).
- A "Needs cart tracking" badge on cart and checkout templates while cart tracking is off.
- "Most popular" sort uses a fixed order based on expected demand (proposal).

**States:** a filter with no match shows the existing "No templates found" message.

**Done when:** all 18 templates are listed, filter and sort work, and the badge disappears once cart tracking is on.

**Milestone:** M1 (filters), M8 (badge)

### Template preview

**Today:** a read-only view of the workflow, a breadcrumb back to the library, a side panel (what it does step by step, plugins used, other versions, Before you start) and Use template.

**v1.0 adds:**
- A side panel next to the canvas and a breadcrumb back to the library (✅ identity phase 1, with Steps and Plugins used) *([identity](plan-identity.md))*. It holds:
  - **Steps:** one line per step with its icon, the same text as on the workflow cards.
  - **Plugins used**, as today.
  - **Other versions:** links to the other templates of the same workflow (e.g. the 3 abandoned cart versions).
  - **Before you start:** what the template needs, with a link to fix it (turn on cart tracking; turn on product reviews).
- ✅ **Use template** replaces Install (identity phase 1); the message says "Draft created. Review it, then activate it." From M1, templates with customer emails say "Review the emails, then activate it".

**States:** "Before you start" is hidden when nothing is missing.

**Done when:** every template's preview shows its steps and requirements, and Use template opens a ready-to-review draft.

**Milestone:** M1 (versions, steps), M3 and M8 (before you start)

### Editor

**Today:** a visual canvas that pans on wheel or trackpad and zooms on Ctrl/Cmd + wheel or pinch, with Order created, Order status changed, Order paid, Order refunded, Order note added, Cart abandoned, Checkout abandoned, Customer account created, Customer inactive, Customer order count reaches, Customer total spend reaches, Review posted (picker sections Order, Carts, Customers, Reviews), actions for staff email, order status, order note, order and customer custom fields (customer field names suggested from other workflows), conditions over order and customer data (products, categories, coupons, countries, totals spent, custom fields and more), "Insert variable", and "Needs setup" / error highlights.

**v1.0 adds:**
- **Pickers:** WooCommerce gets new sections: Carts and Customers for triggers, Coupons for actions. A new YayMatik group holds Wait. *(M1, M2, M4, M6, M8)*
- **Trigger panels:** *(M4, M6, M8)*
  - Checkout / Cart abandoned: no settings, a note on when a cart counts as abandoned (set in Settings), and a warning with a link while cart tracking is off. Each panel explains that a cart never starts both: "Carts that reached checkout start Checkout abandoned instead, never this trigger." / "Only carts that reached checkout with an email. They never start Cart abandoned."
  - Customer account created: no settings.
  - Customer inactive: number of days, a whole number from 7 to 730 (default 90), with "Checked once a day".
- **Wait:** a number and a unit (minutes, hours, days, weeks), up to a year; the card reads e.g. "Wait 1 hour" with a clock icon. *(M2)*
- **Generate coupon:** discount type, amount, expires after N days, minimum spend, individual use, limited to the customer's email, code prefix. The card reads e.g. "10 % off, expires in 7 days". Later emails can include the coupon code. *(M1)*
- **Send email:** To (the customer's email by default; editable, proposal), Subject, Heading, Message, each with "Insert variable". Note under the form: "An unsubscribe link is added automatically". *(M1)*
  - **Preview** shows the email as the customer sees it, with sample data from a recent order or cart.
  - **Send test** sends it to the admin who is logged in.
- **Insert variable:** lists only what the step can use, e.g. the coupon only below a Generate coupon step. *(M1)*
- **Condition:** yes/no checks (first order, unsubscribed, products not reviewed, …) need no value, only "Is true" or "Is false". *(M1)*
- **Condition rule kinds:** each rule draws what its comparison takes: a list of options or a search (products, categories, coupons) with chips, an amount with hours or days, no value for "is set", or a custom field name with its comparison. Customer custom fields suggest names other workflows use. A condition can hold groups, one level deep, each with its own all / any. *(CT)*
- **Warnings before Activate:** a step uses a coupon with no Generate coupon above it; a cart trigger while cart tracking is off. *(M1, M8)*

**States:**
- Preview with no recent order or cart uses made-up sample data, labelled "Sample data"; a product grid then shows the store's 4 newest products.
- Send test that fails shows the error and does not change the workflow.

**Done when:** every step of the 18 templates can be built from scratch in the editor, and Preview matches the email that arrives.

**Milestone:** M1, M2, M4, M6, M8

### Workflow overview

**Today:** a summary row (status, trigger, runs in the last 30 days, last run), the workflow diagram with Draft changes and Version history tabs, the "Workflow runs" table, and the run detail.

**v1.0 adds:**
- **Summary row** (✅ identity phase 1; run cells filled in M2) *([identity](plan-identity.md))*: status (e.g. "Active since Oct 2"), trigger, runs in the last 30 days, last run.
- **Workflow runs** table: started, what it ran for (order number, cart, customer), status (Running, Waiting until …, Completed, Failed, Cancelled), steps run. Pages and a status filter.
- **Run detail:** click a run to see the trigger and each step that ran, with its result and time, e.g. "Condition → No", "Wait → until Oct 9, 3:00 PM", "Send email → sent to a@b.com", "Generate coupon → YAY-7K2P", with a link to the order, or to the user for a customer run. A `?run=` link opens it directly.
- Deactivate and Update say how many waiting runs will be cancelled. Cancelled runs show "Cancelled" right away, never "Waiting until …".

**States:**
- No runs yet: an empty state that says "No runs yet" and "Each time this workflow starts, the run shows up here with every step's result."
- Loading: placeholder rows.
- A failed run shows its error in the row and in the detail.
- Skipped emails show why, e.g. the customer unsubscribed.

**Done when:** every run of the 18 templates appears with the right status, and waiting runs show when they continue.

**Milestone:** M2

### Settings

**Today:** one page with Cart tracking, Emails and Run history, and Health check as the last card. Save changes sits in the app header; leaving with unsaved changes asks first.

**v1.0 adds:**
- **Cart tracking:** on/off (off by default), minutes without activity before a cart counts as abandoned (default 60), capture guest emails at checkout, delete old carts after N days (default 60).
- **Emails:** unsubscribe page text, and a link to WooCommerce email settings (sender, colors).
- **Run history:** keep N days (default 90).

**States:** loading placeholders; a message after saving, or the error if saving fails.

**Done when:** changes are saved and used by the next cart scan and the next cleanup.

**Milestone:** M7

### Store front

**Today:** the unsubscribe confirmation page (M1, its own page, not the theme); the restore link handler and its notices (M7). The email block that carries the link comes in M8.

**v1.0 adds:**
- "Restore my cart" link in reminder emails: refills the cart and opens checkout.
- A simple unsubscribe confirmation page, its own page. ✅ M1

**States:**
- An old or invalid unsubscribe link shows "This link is no longer valid".
- A restore link for a cart that is gone opens the shop with a short message.

**Done when:** both work with the default WooCommerce theme, classic and block checkout.

**Milestone:** M1 (unsubscribe page), M7 (restore link)

### Customer emails

**Today:** every row below.

**v1.0 adds:** every customer email uses the store's WooCommerce email look (logo, colors, footer) and has a subject, a heading and a message the store owner can edit. Ready-made blocks are added from "Insert variable":

| Workflow | What the shopper sees |
|---|---|
| Abandoned cart / checkout | The cart's products (image, name, quantity, price), cart total, a "Restore my cart" button; the coupon versions add a coupon box (code, value, expiry) |
| First-order thank-you | A thank-you message and a coupon box for the next order |
| Cross-sell / upsell | A grid of suggested products (image, name, price, link); the coupon versions add a coupon box |
| Review request | Each product not yet reviewed, with a "Leave a review" button |
| Welcome series | A welcome message with links to the account page and the shop |
| Win-back | A "we miss you" message, a coupon box and a link to the shop |
| High-value order alert (staff) | Unchanged: order number, total, customer, items table, link to the order |

**States:** a block with nothing to show (e.g. no suggested products) is left out; the workflow's conditions normally stop that email first.

**Known limits (reviews):** WordPress's "Users must be registered and logged in to comment" sends guests to a login. WooCommerce's "verified owners only" hides the form for buyers of a guest order. The "Leave a review" link goes to the product's Reviews tab, checked on classic templates only.

**Done when:** each template's emails arrive with the right blocks and look right in common email apps.

**Milestone:** M1 (look, coupon box, unsubscribe), M2 (product grid), M3 (review buttons), M4 (welcome), M6 (win-back), M8 (cart blocks)

Every customer email includes an unsubscribe link. Unsubscribed customers never get workflow emails.

## Templates (18)

| Workflow | Templates | Starts when |
|---|---|---|
| Abandoned checkout | Checkout reminder · Reminder + coupon | A shopper leaves the checkout page without ordering |
| Abandoned cart | Cart reminder · 3-email series · 3-email series + coupon | A shopper leaves products in the cart |
| First-order thank-you | Thank you + % off next order · Thank you + free shipping | A customer's first order is completed |
| Cross-sell / upsell | First-time buyers · Repeat buyers · Cross-sell + coupon · Upsell after 30 days | An order is completed |
| Review request | Review request · Request + reminder | An order is completed |
| Welcome series | Welcome email · 3-email series | A customer creates an account |
| Win-back 90 days | Win-back + discount · Win-back + free shipping | A customer has not ordered for 90 days |
| High-value order alert | High-value order alert (email to staff) | A large order is paid (starts processing, or is completed at once for downloads) |

Template names, timings and coupon values are proposals until confirmed: see [Template details](#template-details-proposals).

Not in v1.0: Branched post-purchase (#8) moves to v1.1. See Out of scope.

### Template details (proposals)

Times count from the moment the workflow starts. All coupons are limited to the customer's email and single use.

| Template | Emails sent | Coupon (type · value · expires) |
|---|---|---|
| Checkout reminder | 1 h | — |
| Checkout reminder + coupon | 1 h, 24 h (with coupon) | % off · 10% · 7 days |
| Cart reminder | 1 h | — |
| Cart 3-email series | 1 h, 24 h, 72 h | — |
| Cart 3-email series + coupon | 1 h, 24 h, 72 h (coupon in 3rd) | % off · 10% · 7 days |
| Thank you + % off next order | Right away | % off · 10% · 30 days |
| Thank you + free shipping | Right away | Free shipping · — · 30 days |
| Cross-sell, first-time buyers | 7 days | — |
| Cross-sell, repeat buyers | 7 days | — |
| Cross-sell + coupon | 14 days | % off · 10% · 14 days |
| Upsell after 30 days | 30 days | % off · 15% · 14 days |
| Review request | 7 days | — |
| Review request + reminder | 7 days, 14 days (only if still not reviewed) | — |
| Welcome email | Right away | — |
| Welcome 3-email series | Day 0, 3, 7 | — |
| Win-back + discount | When 90 days without an order | % off · 15% · 14 days |
| Win-back + free shipping | When 90 days without an order | Free shipping · — · 14 days |
| High-value order alert | Right away, to staff, for orders of 500 or more (store currency, editable) | — |

Cart and checkout times count from when the cart is marked abandoned, which is 60 minutes after the shopper's last activity. So the first reminder arrives about 2 hours after the shopper leaves.

Cart and checkout emails stop as soon as the shopper places an order. Every email is skipped for unsubscribed customers.

### Cart abandoned vs Checkout abandoned

- **Checkout abandoned:** the shopper reached the checkout page and entered an email, then left without ordering. A pending or failed order counts as reaching checkout; a pending order alone never starts it.
- **Cart abandoned:** the shopper added products but never reached checkout. We can only email shoppers we know: logged-in customers, or an email known from an earlier visit in the same browser (WooCommerce session).
- **A cart never starts both.** A cart that reached checkout starts only Checkout abandoned, so a shopper never gets two reminder series for the same cart. The editor explains this on both triggers.
- A cart counts as abandoned after a set time without activity (default 60 minutes, changeable in Settings).

## Milestones

Risk scale: **1** = UI or template content, easy to undo · **2** = new feature logic · **3** = stores new data, runs in the background, emails shoppers or creates coupons; hard to undo. (n) in Delivers = number of templates.

| # | Milestone | Delivers | Owner | Status | Risk | Depends on |
|---|---|---|---|---|---|---|
| M0 | Editor + engine foundation | Visual editor, pickers, conditions, version history, High-value order alert v0 | Jack | Done | — | — |
| M1 | Customer emails + coupons | Send email, Generate coupon, unsubscribe, first-order conditions; **First-order thank-you** (2) | Jack | Todo | 3 | — |
| M2 | Wait + run history | Wait step, run history on the overview page; **Cross-sell / upsell** (4) | Jack | Todo | 3 | M1 |
| M3 | Review request | Review conditions and review links; **Review request** (2) | Jack | Todo | 2 | M2 |
| M4 | Welcome series | Account created trigger; **Welcome series** (2) | Jack | Todo | 2 | M2 |
| M5 | High-value order alert | Template moved to "order is paid"; **High-value order alert** (1) | Jack | Todo | 1 | — |
| M6 | Win-back | Inactive customer trigger (checked daily); **Win-back 90 days** (2) | Jack | Todo | 2 | M2 |
| CT | Custom workflow catalog | 6 triggers, about 25 condition fields, condition groups, 5 actions and a fix to Update order custom field, for workflows built from New Workflow | Jack | Todo | 3 | M6 |
| M7 | Cart tracking + Settings | Tracks carts and guest emails at checkout, restore link, Settings page | Jack | Todo | 3 | — |
| M8 | Abandoned cart + checkout | Both triggers; **Abandoned cart** (3), **Abandoned checkout** (2) | Jack | Todo | 2 | M1, M2, M7 |
| M9 | Release hardening | End-to-end test of all 18 templates, translations, release build; plus items picked from the release checklist below | Jack | Todo | 2 | all |

One developer (Jack) builds v1.0. Full v1.0 scope does not fit by Nov 30 with one developer. Estimates and the release date will be added after M1. M1 builds the core pieces (customer emails, coupons, unsubscribe), so it gives a real measure of pace. The developer also supports other plugins at times, which can shift dates.

**Build order:** M1 → M2 → M7 → M8 → M3 → M4 → M5 → M6 → CT → M9. Abandoned cart brings the most revenue, so it comes right after the foundations.
"Depends on" lists what must exist first, not the order of work: M3, M4 and M6 only need M2; M5 needs nothing and can move earlier if useful.

**Cut candidates if we run late (proposal):** M5 High-value order alert, the "Upsell after 30 days" template, the "Welcome 3-email series" template. If all 3 are cut, v1.0 ships 7 workflows / 16 templates.

## Release criteria

v1.0 can ship when all of these are true:

1. **Every one of the 18 templates** installs, turns on, starts from a real store event, runs end to end (including waits), and the email arrives in the inbox with correct content.
2. Unsubscribe works: the link unsubscribes, and that customer gets no more workflow emails.
3. Coupons from templates work at checkout and expire as set.
4. Deactivating a workflow stops it right away, including runs that are waiting.
5. Restore-cart links refill the cart, and a recovered order stops the reminder series.
6. Works with classic and block checkout, light and dark admin themes; all text translatable.
7. Release checklist items picked for v1.0 (below) are done.

## Release checklist — to decide

These are missing today. They are **not** in scope until picked:

| Item | Today |
|---|---|
| License activation + automatic updates | Not set up yet |
| First-run onboarding | None; first visit shows the empty workflow list |
| User documentation | Help links point to a placeholder page |
| Translation file | Not generated yet |
| WordPress.org-style readme | Missing |
| Data cleanup on uninstall | Not handled |
| "Requires WooCommerce" in plugin header | Missing |
| Version number to 1.0.0 | Still 0.1.0 |

## Main risks

| Risk | Impact | Mitigation |
|---|---|---|
| Emails land in spam on stores without a mail service | Templates look broken | Send test button; docs recommend an SMTP plugin |
| Waits run late on low-traffic stores (background tasks run on site visits) | Reminders arrive late | Document a real server cron; show "waiting until" times in run history |
| Guest email capture differs between classic and block checkout | Fewer carts can be recovered | Support both from the start; test both in M7 |
| Privacy (tracking carts and emails) | Legal complaints | Cart tracking off until turned on (proposal), personal data export/erase, data deleted after a set time |
| Many coupons created | Clutter, misuse | Coupons expire and are limited to the customer's email by default |
| Large stores: daily inactive-customer check and cart tracking load | Slow site | Process in small batches in the background |
| 18 templates to test end to end | Release slips | Test each template as its milestone lands, not only in M9 |

## Out of scope

- **v1.1:** Branched post-purchase, Nth order reward, Negative review alert, Unusual quantity alert, Slack message, order tags.
- **v1.2:** Lifetime spend tiers, Review reward.
- Later: admin lists of unsubscribed customers and tracked carts (in v1.0, run history shows when an email was skipped and why), AI workflow assistant, import, abandoned-cart revenue reports, drag-and-drop email designer, A/B tests, SMS.

## Open questions

Each has a proposed answer, used until confirmed.

| # | Question | Proposal |
|---|---|---|
| 1 | Template names, timings and coupon values | As in [Template details](#template-details-proposals) |
| 2 | Cart tracking on or off after install | Off; the store owner turns it on in Settings |
| 3 | Can the customer email go to any address? | Yes, editable; defaults to the customer's email |
| 4 | Can Wait be set in minutes? | Yes (also useful for testing); hours, days and weeks too |
| 5 | Runs / Last run columns and status filter on the list page in v1.0? | Yes, in M2 |
| 6 | "Most popular" sort before we track installs | Fixed order based on expected demand |
| 7 | Which release checklist items go into v1.0? | None until picked |
| 8 | Release date and estimates | After M1 |
