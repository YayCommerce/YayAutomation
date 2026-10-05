# Plan: Workflow editor — trigger / condition / action pickers

## Goal

Build workflows from scratch in a Shopify-Flow-style editor: "Create workflow" opens an empty workflow, the user picks a trigger, then adds a generic Condition (rule builder) and Actions from a plugin-grouped picker. Variables and available actions follow what the trigger provides.

## Approach

**Visual references** (local only, `.claude/references/`, gitignored): 1 action panel + Add variable, 2–3 trigger picker (plugins → triggers), 4–5 trigger panel with/without config, 6 swap button, 7 Order created card, 8 hover delete, 9 AutomateWoo order triggers, 10–11 condition card + panel, 12 AutomateWoo actions list, 13–16 installed template (canvas, trigger, condition, action panels — data only, keep our style), 17–18 Add criteria AND/OR, 19 AutomateWoo email actions. YayUiKits components + theme tokens only (light/dark).

### Core idea: one data registry, everything else declares against it

- **Data items** (PHP, `includes/Engine/Data/`): `order`, `customer`, `site`. Each declares its fields once: `key`, `label`, `type` (`number` | `string` | `enum` | `date`), `options` (enum), and a resolver. Fields for phase 1:
  - order: number, total, subtotal, status, payment_method, shipping_method, item_count, created_date, admin_url, items (display only: WooCommerce order items table; not offered in conditions)
  - customer: email, first_name, last_name, full_name, phone, billing_country
  - site: name, url (always available)
- **Operators by type**, defined once (PHP evaluator + TS labels share the same ids):
  - number: equal, not_equal, greater_than, greater_or_equal, less_than, less_or_equal
  - string: equal, not_equal, contains, not_contains, starts_with, ends_with, is_empty, is_not_empty
  - enum: is, is_not
  - date: before, after
- **Blocks declare against it:** trigger `provides: ['order', 'customer']`, action `requires: ['order']`, plus `plugin` and (actions) `category`. Rename `app` → `plugin` everywhere (types, blocks, template library). `site` is implicit.
- The data registry, plugin list and each block's `plugin`/`provides`/`requires` reach the client through `yayAutomationMeta`, so the TS side doesn't repeat them. TS blocks keep label, description, schema, form, summary (current pattern).
- Adding a trigger later = one PHP class (hook + `provides`) + one TS folder. Condition, variables and the action picker pick it up with no other change.

### Graph model changes

- `data.block` may be `null` on a trigger or action node = "not selected yet". Card shows kind title + "Select a trigger" / "Select an action". Like any edit, it autosaves into the draft; closing the panel keeps the card.
- `data.title` (never shown) is removed; `data.description` (free per-step note, "Add description") replaces it.
- New issues (block Turn on / Apply, shown in error mode):
  - `no_trigger` — trigger not selected.
  - `no_block` — action not selected.
  - `missing_data` — step uses data the trigger doesn't provide (action `requires`, or a condition/variable referencing it). Covers "change trigger → incompatible steps flagged".
  - Unknown `{{ variable }}` in an action field → `invalid_config`.
- "Exactly one trigger node" stays; it may just be unselected.

### Engine (PHP)

- **Triggers register their own hooks** (`Trigger::register()`), and declare `provides`. `Runner` no longer hard-codes `woocommerce_order_status_changed`.
- **Async:** a trigger event enqueues `yay_automation_run_workflow` (workflow id + serializable args, e.g. order id/from/to) via Action Scheduler on shutdown, once per (workflow, event) per request. The job rebuilds the context and walks the graph. Trigger `matches()` runs at enqueue time so non-matching events never queue.
- **Loop guard:** while a workflow runs on an order, events from that same workflow + order are not enqueued (e.g. Change order status inside Order status changed).
- **Order Created** (no config) — AutomateWoo semantics: `woocommerce_new_order` skipping draft statuses (`auto-draft`, `checkout-draft`, …) + `woocommerce_order_status_changed` from a draft to a real status; fires once per order (meta `_yay_automation_order_created`).
- **Order Status Changed** — `from[]`, `to[]` (both optional, empty = any), `only_checkout` checkbox (`created_via` in checkout/store-api or has cart hash). Description: "Triggers after an order changes status occurs. Set the workflow to run on certain status changes with the trigger options".
- **Condition** (one generic block, id `condition`): config `{ match: 'all' | 'any', rules: [ { field: 'order.total', operator: 'greater_or_equal', value: 500 } ] }`. Shape leaves room for list criteria later. Evaluator reads field type from the registry.
- **Variables:** `{{ order.number }}` (spaces optional) rendered from the registry; one shared renderer for every action field. Display values formatted (e.g. price with currency).
- **Actions** (plugin WooCommerce):
  - Send internal email (category Email; renamed from Send admin email) — sent through WooCommerce's mailer, like its "New order" email:
    - From: WooCommerce email sender (name + address), shown read-only with "The store email used to contact customers" + "Change store email" link to WooCommerce → Settings → Emails.
    - To: blank = WordPress admin email (WooCommerce's default admin recipient). Card shows the resolved address.
    - Subject (also the email heading) and Message (plain textarea; lines → paragraphs, variable values escaped), wrapped in the WooCommerce email template (header, footer, colors).
    - `{{ order.items }}` renders WooCommerce's order items table.
    - Panel description: "Send an email notification to your staff. The email will be sent from your store's contact email address."
  - Change order status (Order).
  - Add order note (Order) — private or to customer, variables allowed.
  - Update order custom field (Order) — meta key + value, variables allowed.
- Remove `OrderTotal` and the hard-coded merge tags in `SendAdminEmail`.
- **REST:** `POST /workflows` with no graph → "New workflow" + one unselected trigger node.

### Editor (React)

- **Left floating panel** replaces the right `Sheet`; canvas stays interactive. Header: block/plugin icon, name • "<Plugin> trigger|action", description, X. "Add description" note. Only Configuration (no tabs for Test results).
- **Trigger picker** (empty trigger card, or the swap button): search "Search plugins and triggers" → plugin rows (icon, name, count, description, `>`) → drill-in with ← back + search → trigger rows (name + description). Only active plugins. Selecting replaces the trigger's block + config.
- **Trigger panel:** form, or "This trigger doesn't require any configuration."
- **"+" menu** (Condition / Action) hidden until the trigger is selected.
  - Condition → node "Condition" / "Select condition", panel opens.
  - Action → node "Action" / "Select an action", panel opens on the action picker: plugins → sections by category; actions not supported by the trigger dimmed + tooltip "Requires order data. Choose an order trigger."
- **Condition panel:** "IF", criteria boxes:
  - "Add a variable" → grouped searchable variable list. Once chosen, the box shows the variable label with its key below (dotted underline); clicking it re-opens the list.
  - Operator select by type; Value input by type (number / text / enum select / date), labelled with the variable label; delete button.
  - One empty criteria to start. With one criteria, "Add criteria ⌄" opens AND / OR; choosing adds a criteria. Between boxes an "AND ⌄" / "OR ⌄" select switches the whole group. With 2+ criteria the button is a plain "Add criteria". Back to one → dropdown again.
  - Card summary lists each criteria, separated by an AND / OR line, e.g. "Order total is greater than or equal to 500". Then / Otherwise handles unchanged.
- **Add variable** on action text fields: same grouped list, inserts `{{ key }}` at the cursor.
- **Hover buttons** outside the card, right side: swap (trigger), delete (condition/action). Same style as the existing "+" button.
- **Create workflow** on the list page enabled: POST → navigate to the editor.
- Read-only canvas (overview, template preview) renders the new empty states too.
- **Template "High-value order alert"** (refs 13–16): trigger Order created; Condition `order.total` greater_or_equal 500; Send internal email with To blank, Subject `High-value order {{ order.number }} - {{ order.total }}`, Message:
  ```
  A high-value order was placed on {{ site.name }}.

  Order: {{ order.number }}
  Total: {{ order.total }}
  Customer: {{ customer.first_name }} {{ customer.last_name }} ({{ customer.email }})

  Items:
  {{ order.items }}
  ```
  Template plugins derived from its blocks → WooCommerce only.

## Checkpoints

1. Engine + REST — Risk: 3 — data registry, operators, trigger self-registration + Action Scheduler + loop guard, Order Created / Order Status Changed, generic Condition, variable renderer, 4 actions, graph model (nullable block, description, new issues), create endpoint, localized meta, remove OrderTotal. Test: REST in console + place/advance orders and check emails/notes/logs.
2. Editor shell — Risk: 2 — left panel, trigger picker, empty/selected card states, hover swap/delete, "+" gating, Add description, Create workflow flow, read-only canvas. Test in browser (light + dark).
3. Condition + actions UI — Risk: 2 — rule builder, variable list, action picker with dimming, 4 action forms with Add variable, template update, template library plugins. Test in browser + run the template end to end.

## Out of Scope

- Test results tab, "Select test event", run history.
- Liquid loops / code editor for message fields; Message copy / magic icons.
- Using a variable as a condition value (`</>`).
- Nested condition groups and list criteria (shape is ready for them).
- Phase 2 triggers: Order Paid, Processing, Completed, Cancelled, On Hold, Pending Payment, Refunded, Refunded Manually, Note Added, "Each Line Item" variants, Includes Product from Taxonomy Term, Includes Product Variation with Attribute; later Customer, Carts, Checkout, Reviews, Guests.
- Phase 2 actions: Send email to any recipient (customer), Send email – Plain Text, Send email – Raw HTML, Resend order email, Trigger order action, Update customer provided note, Customer change role / custom field / add-remove tags, Order item & Product custom field, Custom function, Change post status, Change workflow status, integrations (other Yay plugins, Mailchimp, …).

## Meta

- Target branch: jack/feature/workflow-editor-pickers
- Base branch: develop
- No commits without asking first.
