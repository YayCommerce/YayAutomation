=== YayMatik - Automation for WooCommerce ===
Contributors: yaycommerce
Tags: automation, workflow, abandoned cart, email marketing, follow-up emails
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automate your WooCommerce store's emails and routine work: recover abandoned carts, thank first-time buyers, ask for reviews, win back customers.

== Description ==

YayMatik runs workflows for your WooCommerce store. A workflow starts on a store event, such as an order being completed or a cart being left behind, checks its conditions, and then acts: it sends an email, creates a coupon, waits, or updates an order.

Start from one of 18 ready-made templates, or build your own on a visual canvas.

**Ready-made workflows**

* Abandoned cart: a reminder, or a 3-email series, with or without a coupon.
* Abandoned checkout: a reminder, with or without a coupon.
* First-order thank-you, with a discount or free shipping on the next order.
* Cross-sell and upsell emails after an order.
* Review requests, with an optional reminder.
* Welcome emails for new accounts.
* Win-back emails for customers who haven't ordered in 90 days.
* An alert to your team about large orders.

**Build your own**

* Triggers: order created, paid, status changed, refunded, note added; cart or checkout abandoned; account created; review posted; customer inactive; order count or total spend reached.
* Conditions on the order, the customer, the cart and its products, with groups and relative dates.
* Actions: send an email to the customer in your store's WooCommerce email style, email your team, generate a personal coupon, wait for a time or until a date, add an order note, change the order status, update order or customer custom fields, change a customer's role, resend a WooCommerce email.

**Run history**

Every run is kept with each step's result, so you can see what was sent, skipped or failed, and why. A Health check in Settings finds runs that got stuck and fixes them.

**Unsubscribe**

Every customer email has an unsubscribe link. Customers who unsubscribe get no more workflow emails.

== Installation ==

1. Install and activate WooCommerce.
2. In Plugins › Add New, search for "YayMatik", then install and activate it. Or upload the zip in Plugins › Add New › Upload Plugin.
3. Go to YayCommerce › YayMatik and choose Browse templates.
4. For the abandoned cart and checkout workflows, turn on Cart tracking in YayMatik › Settings.

== Frequently Asked Questions ==

= Why does a waiting email go out late? =

Workflows run in the background with WooCommerce's Action Scheduler, which runs when your site has visitors (WP-Cron). On a quiet site, or a host where the site can't call itself, waits can end late. Run WP-Cron from the server instead, for example every minute: `wp action-scheduler run`. The Health check in Settings shows when jobs are late.

= Does it work with the block checkout and the classic checkout? =

Yes. Cart tracking and guest email capture work with both.

= What happens to my data when I delete the plugin? =

Deleting the plugin removes its scheduled jobs and temporary data. Your workflows, run history, carts, settings and unsubscribes stay for a reinstall. Runs that were waiting then don't resume on their own: the Health check in Settings queues them again. To remove all of them, add this line to `wp-config.php` before you delete the plugin:

`define( 'YAYMATIK_REMOVE_ALL_DATA', true );`

Coupons that workflows created stay in WooCommerce, since customers may still use them.

= Does it send email itself? =

No. It sends through WordPress (`wp_mail`), like WooCommerce does. On a host without a mail service, use an SMTP plugin so emails arrive.

== Privacy ==

* Cart tracking is off until you turn it on in Settings. When on, YayMatik stores carts of logged-in customers, and of guests once they type an email address at checkout, so the abandoned cart workflows can email them. Guest email capture can be turned off on its own.
* Carts and run history are deleted after the number of days you set in Settings (60 and 90 days by default).
* Unsubscribed email addresses are kept, so those customers get no more workflow emails.
* YayMatik adds its data to WordPress's personal data export and erase tools (Tools › Export Personal Data, Tools › Erase Personal Data).

== External services ==

YayMatik doesn't connect to any external service. Emails go out through your site's own mail setup.

== Source code ==

The admin app ships built (`assets/dist/`). Its source code and build steps are public:

https://github.com/YayCommerce/YayMatik

To build it, with Node.js 22.13 or later and pnpm 9 or later:

1. `pnpm install`
2. `pnpm build`

This rebuilds `assets/dist/`. The PHP in `vendor-prefixed/` is the YayCommerce admin menu package, shipped as built (prefixed) PHP.

**Third-party libraries in the admin app**

React, ReactDOM and the JSX runtime come from WordPress (the `react`, `react-dom` and `react-jsx-runtime` scripts). Bundled:

* React Router 7.18.4, https://reactrouter.com (MIT)
* TanStack Query 5.103.2 and TanStack Form 1.33.5, https://tanstack.com (MIT)
* Radix UI primitives, https://www.radix-ui.com (MIT)
* React Flow (@xyflow/react) 12.11.6, https://reactflow.dev (MIT)
* Motion 12.43.0, https://motion.dev (MIT)
* Lucide icons 0.562.0, https://lucide.dev (ISC)
* Valibot 1.5.0, https://valibot.dev (MIT)
* ky 1.14.3, https://github.com/sindresorhus/ky (MIT)
* Sonner 2.0.8, https://sonner.emilkowal.ski (MIT)
* cmdk 1.1.1, https://cmdk.paco.me (MIT)
* react-number-format 5.4.5, https://github.com/s-yadav/react-number-format (MIT)
* class-variance-authority 0.7.1, clsx 2.1.1 and tailwind-merge 3.7.0 (MIT)
* Tailwind CSS 4.3.3, https://tailwindcss.com (MIT)

== Changelog ==

= 1.0.0 =
* First release.
