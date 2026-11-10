# Workflow Template Library — v1.0 (external list, 2026-10-07)

Pasted by the user from an outside review. Reference for the template naming follow-up branch (see memory `template-naming-followup`). Not the roadmap: names and categories differ from `docs/ROADMAP-v1.0.md`; timings and coupons match.

## 1. Cart & Checkout Recovery

### Abandoned cart recovery

**Abandoned cart: Reminder**
Send a reminder email 1 hour after a cart is marked as abandoned, for customers with a known email address who have not reached checkout. Include the cart's products and a link to restore the cart and return to checkout.

**Abandoned cart: 3-email sequence**
Send recovery emails 1, 24, and 72 hours after a cart is marked as abandoned. Include the cart's products and a cart recovery link in each email. Stop the sequence when the customer places an order.

**Abandoned cart: 3-email sequence (with coupon)**
Send recovery emails 1, 24, and 72 hours after a cart is marked as abandoned. Include a cart recovery link in each email and a personalized 10% discount coupon, valid for 7 days, in the final email. Stop the sequence when the customer places an order.

### Abandoned checkout recovery

**Abandoned checkout: Reminder**
Send a recovery email 1 hour after a checkout is marked as abandoned, for shoppers who reached checkout and provided an email address but have not placed an order. Include the cart's products and a link to restore the cart and return to checkout.

**Abandoned checkout: Reminder sequence (with coupon)**
Send recovery emails 1 and 24 hours after a checkout is marked as abandoned. Include a cart recovery link in both emails and a personalized 10% discount coupon, valid for 7 days, in the second email. Stop the sequence when the shopper places an order.

## 2. Welcome & Onboarding

### Welcome emails

**Welcome: New customer account**
Send a welcome email immediately after a customer creates an account. Include links to their account page and your shop.

**Welcome: New customer account (3-email sequence)**
Send three welcome emails on days 0, 3, and 7 after a customer creates an account. Introduce your store and include helpful links to their account page and your shop.

## 3. Post-purchase

### First-order thank-you

**First order: Thank-you (with next-order discount)**
Send a thank-you email immediately when a customer's first order reaches Completed status. Include a personalized 10% discount coupon for their next order, valid for 30 days.

**First order: Thank-you (with free shipping)**
Send a thank-you email immediately when a customer's first order reaches Completed status. Include a personalized free shipping coupon for their next order, valid for 30 days.

### Cross-sell & Upsell

**Cross-sell: First-time customers**
Send an email 7 days after a customer's first order reaches Completed status, recommending complementary products based on the items they purchased.

**Cross-sell: Returning customers**
Send an email to returning customers 7 days after an order reaches Completed status, recommending complementary products based on the items they purchased.

**Cross-sell: Product recommendations (with coupon)**
Send an email 14 days after an order reaches Completed status, recommending complementary products. Include a personalized 10% discount coupon, valid for 14 days.

**Upsell: 30 days after order completion**
Send an email 30 days after an order reaches Completed status, recommending upgrades or higher-value alternatives to the purchased products. Include a personalized 15% discount coupon, valid for 14 days.

### Product review requests

**Review request: 7 days after order completion**
Send an email 7 days after an order reaches Completed status, asking customers to review purchased products they have not yet reviewed. Include a review link for each product.

**Review request: Request + reminder**
Send a review request 7 days after an order reaches Completed status, followed by a reminder on day 14 if any purchased products remain unreviewed. Each email includes only products the customer has not yet reviewed.

## 4. Customer Win-back

### Win-back emails

**Win-back: No orders for 90 days (with discount)**
Send a win-back email when a customer has not placed an order for 90 days. Include a personalized 15% discount coupon, valid for 14 days, and a link to your shop.

**Win-back: No orders for 90 days (with free shipping)**
Send a win-back email when a customer has not placed an order for 90 days. Include a personalized free shipping coupon, valid for 14 days, and a link to your shop.

## 5. Order Management & Alerts

### High-value order alerts

**Orders: High-value order alert**
Send an email to store staff when an order enters Processing status and its total exceeds a configured threshold. The default threshold is 500 in the store's currency and can be edited. Include the order number, total, customer details, ordered items, and a link to the order.
