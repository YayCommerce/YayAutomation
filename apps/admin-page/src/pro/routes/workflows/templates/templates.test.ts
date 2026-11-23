import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import type { WaitConfig } from '@/routes/workflows/steps/actions/wait/schema';
import {
  findTemplate,
  needsProductReviews,
  sendsCustomerEmail,
  templates,
  versionsOf,
} from '@/routes/workflows/templates';
import { emailMessage } from '@/routes/workflows/templates/core/email';

const graphOf = (id: string) => findTemplate(id)!.graph;
const emailsOf = (id: string) =>
  graphOf(id)
    .nodes.filter((node) => node.data.block === 'send_email')
    .map((node) => node.data.config);
const greeting = '<p>Hi {{ customer.first_name | default: "there" }},</p>';

it('adds its 11 templates to the 8 of the free edition', () => {
  expect(templates.map((template) => template.id)).toEqual([
    'cart-reminder',
    'checkout-reminder',
    'checkout-reminder-coupon',
    'high-value-order-alert',
    'first-order-thank-you-discount',
    'first-order-thank-you-free-shipping',
    'review-request',
    'welcome-email',
    'cart-series',
    'cart-series-coupon',
    'cross-sell-first-time-buyers',
    'cross-sell-repeat-buyers',
    'cross-sell-coupon',
    'upsell-after-30-days',
    'review-request-reminder',
    'welcome-series',
    'win-back-discount',
    'win-back-free-shipping',
    'login-no-cart',
  ]);
});

describe('win-back', () => {
  const node = (id: string, block: string) => findTemplate(id)!.graph.nodes.find((n) => n.data.block === block)!;
  const ids = ['win-back-discount', 'win-back-free-shipping'];

  it('links its two versions', () => {
    expect(versionsOf(findTemplate(ids[0])!).map((t) => t.id)).toEqual([ids[1]]);
    expect(versionsOf(findTemplate(ids[1])!).map((t) => t.id)).toEqual([ids[0]]);
  });

  it.each(ids)('%s waits 90 days, checks the unsubscribe, and hangs the coupon from Yes', (id) => {
    const { graph } = findTemplate(id)!;
    expect(graph.nodes.map((n) => n.data.block)).toEqual([
      'customer_inactive',
      'condition',
      'generate_coupon',
      'send_email',
    ]);
    expect(graph.nodes[0].data.config).toEqual({ days: 90 });
    expect(graph.nodes[1].data.config).toEqual({
      match: 'all',
      rules: [{ field: 'customer.unsubscribed', operator: 'is_false', value: '', valueType: 'value' }],
    });
    expect(graph.edges.find((edge) => edge.target === 'coupon')).toMatchObject({
      source: 'condition',
      sourceHandle: 'then',
    });
  });

  it('offers 15% off or free shipping, 14 days, limited to the customer', () => {
    expect(node(ids[0], 'generate_coupon').data.config).toMatchObject({
      discount_type: 'percent',
      amount: 15,
      expiry_days: 14,
      limit_to_customer: true,
    });
    expect(node(ids[1], 'generate_coupon').data.config).toMatchObject({
      discount_type: 'free_shipping',
      amount: null,
      expiry_days: 14,
      limit_to_customer: true,
    });
  });

  it.each([
    [ids[0], 'Here’s {{ steps.coupon.amount }} off your next one:'],
    [ids[1], 'Here’s free shipping on your next order:'],
  ])('%s writes the email with each block on its own paragraph', (id, offer) => {
    const { config } = node(id, 'send_email').data;
    expect(sendsCustomerEmail(findTemplate(id)!.graph)).toBe(true);
    expect(config.to).toBe('{{ customer.email }}');
    expect(config.subject).toBe('We miss you at {{ site.name }}');
    expect(config.heading).toBe('It’s been a while');
    expect(config.message).toBe(
      emailMessage([
        'Hi {{ customer.first_name | default: "there" }},',
        `It’s been a while since your last order. ${offer}`,
        '{{ steps.coupon.box }}',
        '{{ site.shop_button }}',
        'See you soon,\n{{ site.name }}',
      ]),
    );
  });
});

describe('cart series', () => {
  const hours = (id: string) =>
    graphOf(id)
      .nodes.filter((node) => node.data.block === 'wait')
      .reduce((sum, node) => {
        const { value, unit } = (node.data.config as WaitConfig).delay;
        return sum + value! * (unit === 'days' ? 24 : 1);
      }, 0);

  it('are versions of the cart reminder', () => {
    expect(versionsOf(findTemplate('cart-reminder')!).map((t) => t.id)).toEqual(['cart-series', 'cart-series-coupon']);
  });

  it.each(['cart-series', 'cart-series-coupon'])('%s starts from cart_abandoned and ends 72 hours later', (id) => {
    expect(graphOf(id).nodes[0].data.block).toBe('cart_abandoned');
    expect(hours(id)).toBe(72);
  });

  it('cart-series-coupon makes a 10% coupon for 7 days right before its last email', () => {
    const { nodes, edges } = graphOf('cart-series-coupon');
    const coupon = nodes.find((node) => node.data.block === 'generate_coupon')!;
    expect(coupon.data.config).toMatchObject({ discount_type: 'percent', amount: 10, expiry_days: 7 });
    const next = nodes.find((node) => node.id === edges.find((edge) => edge.source === coupon.id)!.target)!;
    expect(next.data.block).toBe('send_email');
    expect(next.data.config.message).toContain('<p>{{ steps.coupon.box }}</p>');
    expect(edges.some((edge) => edge.source === next.id)).toBe(false);
  });

  it.each(['cart-series', 'cart-series-coupon'])('%s emails the cart to the shopper in every email', (id) => {
    for (const email of emailsOf(id)) {
      expect(email.to).toBe('{{ customer.email }}');
      expect(email.message).toContain('<p>{{ cart.items }}</p>\n<p>{{ cart.restore_button }}</p>');
      expect(email.message).toContain(greeting);
    }
  });

  it.each([
    [
      'cart-series',
      [
        ['You left something in your cart', 'Your cart is saved'],
        ['Still thinking it over?', 'Your cart is waiting'],
        ['Last reminder about your cart', 'Your cart is still here'],
      ],
    ],
    [
      'cart-series-coupon',
      [
        ['You left something in your cart', 'Your cart is saved'],
        ['Still thinking it over?', 'Your cart is waiting'],
        ['{{ steps.coupon.amount }} off the cart you left', 'A little something to help you decide'],
      ],
    ],
  ])('%s has the agreed subjects and headings', (id, expected) => {
    expect(emailsOf(id).map((email) => [email.subject, email.heading])).toEqual(expected);
  });
});

describe('review, welcome and cross-sell templates', () => {
  const stillValid = [
    { field: 'order.status', operator: 'is', value: 'completed', valueType: 'value' },
    { field: 'order.has_products_not_reviewed', operator: 'is_true', value: '', valueType: 'value' },
    { field: 'customer.unsubscribed', operator: 'is_false', value: '', valueType: 'value' },
  ];
  const parentOf = (id: string, child: string) => graphOf(id).edges.find((edge) => edge.target === child);

  it('links the versions of each workflow', () => {
    expect(versionsOf(findTemplate('review-request')!).map((t) => t.id)).toEqual(['review-request-reminder']);
    expect(versionsOf(findTemplate('welcome-email')!).map((t) => t.id)).toEqual(['welcome-series']);
  });

  it('sends the reminder only after the first email, after another wait and check', () => {
    const { nodes } = graphOf('review-request-reminder');
    expect(nodes.map((node) => node.id)).toEqual([
      'trigger',
      'condition',
      'wait',
      'still-valid',
      'email',
      'wait-2',
      'still-valid-2',
      'email-2',
    ]);
    expect(parentOf('review-request-reminder', 'wait-2')?.source).toBe('email');
    expect((nodes[5].data.config as WaitConfig).delay).toEqual({ value: 7, unit: 'days' });
    expect(nodes[6].data.config).toEqual({ match: 'all', rules: stillValid });
    expect(parentOf('review-request-reminder', 'email-2')).toMatchObject({
      source: 'still-valid-2',
      sourceHandle: 'then',
    });
  });

  it('welcome-series waits 3 and 4 days, with no condition or coupon', () => {
    const { nodes } = graphOf('welcome-series');
    expect(nodes.map((node) => node.data.block)).toEqual([
      'customer_account_created',
      'send_email',
      'wait',
      'send_email',
      'wait',
      'send_email',
    ]);
    expect((nodes[2].data.config as WaitConfig).delay).toEqual({ value: 3, unit: 'days' });
    expect((nodes[4].data.config as WaitConfig).delay).toEqual({ value: 4, unit: 'days' });
  });

  it.each([
    [
      'review-request-reminder',
      [
        ['How did your order go?', 'Tell us what you think'],
        ['A quick review?', 'Still have a minute?'],
      ],
    ],
    [
      'welcome-series',
      [
        ['Welcome to {{ site.name }}', 'Welcome to {{ site.name }}'],
        ['New in the shop', 'New in the shop'],
        ['Anything we can help with?', 'Anything we can help with?'],
      ],
    ],
  ])('%s has the agreed subjects and headings', (id, expected) => {
    expect(emailsOf(id).map((email) => [email.subject, email.heading])).toEqual(expected);
  });

  it.each(['cross-sell-first-time-buyers', 'cross-sell-repeat-buyers', 'cross-sell-coupon', 'upsell-after-30-days'])(
    '%s greets a customer with no first name',
    (id) => {
      expect(emailsOf(id)[0].message).toContain(greeting);
    },
  );
});

describe('needsProductReviews', () => {
  const { wc } = window.yayMatikMeta;
  const before = { ...wc };

  afterEach(() => {
    Object.assign(wc, before);
  });

  it('holds for the reminder and skips cross-sell while reviews are off', () => {
    wc.reviewsEnabled = false;
    expect(needsProductReviews(graphOf('review-request-reminder'))).toBe(true);
    expect(needsProductReviews(graphOf('cross-sell-coupon'))).toBe(false);
  });
});

describe('logged in, nothing added to cart', () => {
  const { graph } = findTemplate('login-no-cart')!;

  it('waits an hour after a sign-in or return, then emails only when nothing was added since', () => {
    expect(graph.nodes.map((n) => n.data.block)).toEqual(['customer_logged_in', 'wait', 'condition', 'send_email']);
    expect(graph.nodes[0].data.config).toEqual({ when: ['sign_in', 'return'], days: 7 });
    expect(graph.nodes[1].data.config).toEqual({ delay: { value: 1, unit: 'hours' } } satisfies WaitConfig);
    expect(graph.nodes[2].data.config).toEqual({
      match: 'any',
      rules: [
        { field: 'customer.last_added_to_cart', operator: 'is_not_set', value: '', valueType: 'value' },
        { field: 'customer.last_added_to_cart', operator: 'before', value: 'trigger.time', valueType: 'variable' },
      ],
    });
    expect(graph.edges.find((e) => e.target === 'email')?.sourceHandle).toBe('then');
    expect(sendsCustomerEmail(graph)).toBe(true);
  });

  it('is the graph check-login runs through the PHP engine', () => {
    const fixture = resolve(__dirname, '../../../../../../../tests/Unit/Pro/fixtures/login-no-cart.json');
    expect(graph).toEqual(JSON.parse(readFileSync(fixture, 'utf8')));
  });
});
