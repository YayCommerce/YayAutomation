import type { WorkflowGraph } from '@yaymatik/types';
import { afterEach, describe, expect, it } from 'vitest';

import { structureErrors } from '../model/structure';
import { validateWorkflow } from '../model/validation';
import type { WaitConfig } from '../steps/actions/wait/schema';
import { emailMessage } from './core/email';
import {
  findTemplate,
  needsCartTracking,
  needsProductReviews,
  sendsCustomerEmail,
  templates,
  versionsOf,
  workflowRank,
} from './index';

describe('templates', () => {
  it('has templates with unique ids', () => {
    expect(templates.length).toBeGreaterThan(0);
    expect(new Set(templates.map((template) => template.id)).size).toBe(templates.length);
  });

  it.each(templates.map((template) => [template.id, template] as const))(
    '%s saves and can be activated as is',
    (_id, template) => {
      expect(structureErrors(template.graph)).toEqual([]);
      expect(validateWorkflow({ name: template.name, graph: template.graph })).toEqual([]);
    },
  );

  it('ranks "Most popular" by the fixed workflow order', () => {
    const ranked = Object.entries(workflowRank).sort(([, a], [, b]) => a - b);
    expect(ranked.map(([workflow]) => workflow)).toEqual([
      'abandoned_cart',
      'abandoned_checkout',
      'first_order_thank_you',
      'cross_sell_upsell',
      'review_request',
      'welcome',
      'win_back',
      'high_value_order_alert',
    ]);
  });

  it('links the versions of one workflow, not the template itself', () => {
    const discount = findTemplate('first-order-thank-you-discount')!;
    expect(versionsOf(discount).map((t) => t.id)).toEqual(['first-order-thank-you-free-shipping']);
    expect(versionsOf(findTemplate('high-value-order-alert')!)).toEqual([]);
  });

  it.each(['first-order-thank-you-discount', 'first-order-thank-you-free-shipping'])(
    '%s emails the coupon box on its own paragraph',
    (id) => {
      const { graph } = findTemplate(id)!;
      expect(sendsCustomerEmail(graph)).toBe(true);
      const email = graph.nodes.find((node) => node.data.block === 'send_email')!;
      expect(email.data.config.message).toContain('<p>{{ steps.coupon.box }}</p>');
      expect(email.data.config.subject).toBe('Thanks for your first order — a gift for your next one');
      // A guest order may have no first name.
      expect(email.data.config.message).toContain('<p>Hi {{ customer.first_name | default: "there" }},</p>');
      const coupon = graph.nodes.find((node) => node.data.block === 'generate_coupon')!;
      expect(coupon.data.config).toMatchObject({ code_prefix: 'YM', expiry_days: 30 });
    },
  );

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

  it('staff-only templates send no customer email', () => {
    expect(sendsCustomerEmail(findTemplate('high-value-order-alert')!.graph)).toBe(false);
  });

  it('high-value alert fires at 500 or more when the order is paid', () => {
    const { nodes } = findTemplate('high-value-order-alert')!.graph;
    expect(nodes.map((node) => node.data.block)).toEqual(['order_status_changed', 'condition', 'send_internal_email']);
    expect(nodes[0].data.config).toEqual({
      from: ['pending', 'on-hold', 'failed'],
      to: ['processing', 'completed'],
      only_checkout: false,
    });
    expect(nodes[1].data.config).toEqual({
      match: 'all',
      rules: [{ field: 'order.total', operator: 'greater_or_equal', value: '500' }],
    });
    expect(nodes[2].data.config).toMatchObject({
      recipients: '',
      subject: 'High-value order {{ order.number }} - {{ order.total }}',
    });
    expect(nodes[2].data.config.message).toContain('A high-value order was placed on {{ site.name }}.');
    expect(nodes[2].data.config.message).toContain('{{ order.items }}');
  });
});

describe('cart and checkout templates', () => {
  const hours = (id: string) =>
    findTemplate(id)!
      .graph.nodes.filter((node) => node.data.block === 'wait')
      .reduce((sum, node) => {
        const { value, unit } = (node.data.config as WaitConfig).delay;
        return sum + value! * (unit === 'days' ? 24 : 1);
      }, 0);
  const emailsOf = (id: string) =>
    findTemplate(id)!
      .graph.nodes.filter((node) => node.data.block === 'send_email')
      .map((n) => n.data.config);

  it('links the versions of each workflow', () => {
    expect(versionsOf(findTemplate('cart-reminder')!).map((t) => t.id)).toEqual(['cart-series', 'cart-series-coupon']);
    expect(versionsOf(findTemplate('checkout-reminder')!).map((t) => t.id)).toEqual(['checkout-reminder-coupon']);
  });

  it.each([
    ['cart-reminder', 1],
    ['cart-series', 72],
    ['cart-series-coupon', 72],
    ['checkout-reminder', 1],
    ['checkout-reminder-coupon', 24],
  ])('%s sends its last email %d hours after the start', (id, total) => {
    expect(hours(id)).toBe(total);
  });

  it.each(['cart-series-coupon', 'checkout-reminder-coupon'])(
    '%s makes a 10%% coupon for 7 days right before its last email',
    (id) => {
      const { nodes, edges } = findTemplate(id)!.graph;
      const coupon = nodes.find((node) => node.data.block === 'generate_coupon')!;
      expect(coupon.data.config).toMatchObject({ discount_type: 'percent', amount: 10, expiry_days: 7 });
      const next = nodes.find((node) => node.id === edges.find((edge) => edge.source === coupon.id)!.target)!;
      expect(next.data.block).toBe('send_email');
      expect(next.data.config.message).toContain('<p>{{ steps.coupon.box }}</p>');
      expect(edges.some((edge) => edge.source === next.id)).toBe(false);
    },
  );

  it.each([
    ['cart-reminder', 'cart_abandoned'],
    ['cart-series', 'cart_abandoned'],
    ['cart-series-coupon', 'cart_abandoned'],
    ['checkout-reminder', 'checkout_abandoned'],
    ['checkout-reminder-coupon', 'checkout_abandoned'],
  ])('%s starts from %s', (id, trigger) => {
    expect(findTemplate(id)!.graph.nodes[0].data.block).toBe(trigger);
  });

  it.each(['cart-reminder', 'cart-series', 'cart-series-coupon', 'checkout-reminder', 'checkout-reminder-coupon'])(
    '%s emails the cart to the shopper in every email',
    (id) => {
      const emails = emailsOf(id);
      expect(emails.length).toBeGreaterThan(0);
      for (const email of emails) {
        expect(email.to).toBe('{{ customer.email }}');
        expect(email.message).toContain('<p>{{ cart.items }}</p>\n<p>{{ cart.restore_button }}</p>');
        expect(email.message).toContain('<p>Hi {{ customer.first_name | default: "there" }},</p>');
        expect(email.message).toContain('<p>See you soon,<br>\n{{ site.name }}</p>');
      }
    },
  );

  it.each([
    ['cart-reminder', [['You left something in your cart', 'Your cart is saved']]],
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
    ['checkout-reminder', [['Did something go wrong at checkout?', 'Finish your order']]],
    [
      'checkout-reminder-coupon',
      [
        ['Did something go wrong at checkout?', 'Finish your order'],
        ['{{ steps.coupon.amount }} off to finish your order', 'Your order is still waiting'],
      ],
    ],
  ])('%s has the agreed subjects and headings', (id, expected) => {
    expect(emailsOf(id).map((email) => [email.subject, email.heading])).toEqual(expected);
  });
});

describe('review and welcome templates', () => {
  const graphOf = (id: string) => findTemplate(id)!.graph;
  const emailsOf = (id: string) =>
    graphOf(id)
      .nodes.filter((node) => node.data.block === 'send_email')
      .map((node) => node.data.config);
  const greeting = '<p>Hi {{ customer.first_name | default: "there" }},</p>';
  const signOff = '<p>See you soon,<br>\n{{ site.name }}</p>';
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

  it('checks for products, waits 7 days, then checks again before the review email', () => {
    const { nodes } = graphOf('review-request');
    expect(nodes.map((node) => node.id)).toEqual(['trigger', 'condition', 'wait', 'still-valid', 'email']);
    expect(nodes[0].data).toEqual({
      block: 'order_status_changed',
      config: { from: [], to: ['completed'], only_checkout: false },
    });
    expect(nodes[1].data.config.rules).toEqual([stillValid[1]]);
    expect(parentOf('review-request', 'wait')).toMatchObject({ source: 'condition', sourceHandle: 'then' });
    expect((nodes[2].data.config as WaitConfig).delay).toEqual({ value: 7, unit: 'days' });
    expect(nodes[3].data.config).toEqual({ match: 'all', rules: stillValid });
    expect(parentOf('review-request', 'email')).toMatchObject({ source: 'still-valid', sourceHandle: 'then' });
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

  it('welcomes right away, then waits 3 and 4 days, with no condition or coupon', () => {
    expect(graphOf('welcome-email').nodes.map((node) => node.data.block)).toEqual([
      'customer_account_created',
      'send_email',
    ]);
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
    ['review-request', [['How did your order go?', 'Tell us what you think', ['{{ order.review_products }}']]]],
    [
      'review-request-reminder',
      [
        ['How did your order go?', 'Tell us what you think', ['{{ order.review_products }}']],
        ['A quick review?', 'Still have a minute?', ['{{ order.review_products }}']],
      ],
    ],
    ['welcome-email', [['Welcome to {{ site.name }}', 'Welcome to {{ site.name }}', ['{{ site.welcome_links }}']]]],
    [
      'welcome-series',
      [
        ['Welcome to {{ site.name }}', 'Welcome to {{ site.name }}', ['{{ site.welcome_links }}']],
        ['New in the shop', 'New in the shop', ['{{ site.new_products }}', '{{ site.shop_button }}']],
        ['Anything we can help with?', 'Anything we can help with?', ['{{ site.account_button }}']],
      ],
    ],
  ] as const)('%s has the agreed emails, with blocks on their own paragraphs', (id, expected) => {
    const emails = emailsOf(id);
    expect(emails.map((email) => [email.subject, email.heading])).toEqual(expected.map(([s, h]) => [s, h]));
    emails.forEach((email, i) => {
      expect(email.to).toBe('{{ customer.email }}');
      expect(email.message).toContain(greeting);
      expect(email.message).toContain(signOff);
      for (const block of expected[i][2]) expect(email.message).toContain(`<p>${block}</p>`);
    });
  });

  it('asks for a review with the order number, without a #', () => {
    expect(emailsOf('review-request')[0].message).toContain(
      'Thanks again for order {{ order.number }}. If you have a minute',
    );
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

  it('holds for the review templates while reviews are off', () => {
    wc.reviewsEnabled = false;
    expect(needsProductReviews(findTemplate('review-request')!.graph)).toBe(true);
    expect(needsProductReviews(findTemplate('review-request-reminder')!.graph)).toBe(true);
  });

  it('goes away when reviews are on', () => {
    wc.reviewsEnabled = true;
    expect(needsProductReviews(findTemplate('review-request')!.graph)).toBe(false);
  });

  it('leaves WooCommerce being off to "Install WooCommerce"', () => {
    wc.reviewsEnabled = false;
    wc.active = false;
    expect(needsProductReviews(findTemplate('review-request')!.graph)).toBe(false);
  });

  it('skips other templates', () => {
    wc.reviewsEnabled = false;
    expect(needsProductReviews(findTemplate('welcome-email')!.graph)).toBe(false);
    expect(needsProductReviews(findTemplate('cross-sell-coupon')!.graph)).toBe(false);
  });
});

describe('needsCartTracking', () => {
  const { wc } = window.yayMatikMeta;
  const before = { ...wc };
  const trigger = (block: string) =>
    ({
      nodes: [{ id: 't', kind: 'trigger', position: { x: 0, y: 0 }, data: { block, config: {} } }],
      edges: [],
    }) as WorkflowGraph;

  afterEach(() => {
    Object.assign(wc, before);
  });

  it('holds for a cart or checkout trigger while tracking is off', () => {
    wc.cartTracking = false;
    expect(needsCartTracking(trigger('cart_abandoned'))).toBe(true);
    expect(needsCartTracking(trigger('checkout_abandoned'))).toBe(true);
    expect(needsCartTracking(trigger('order_created'))).toBe(false);
  });

  it('leaves WooCommerce being off to "Install WooCommerce"', () => {
    wc.cartTracking = false;
    wc.active = false;
    expect(needsCartTracking(trigger('cart_abandoned'))).toBe(false);
  });

  it('goes away as soon as tracking is on', () => {
    wc.cartTracking = true;
    expect(needsCartTracking(trigger('cart_abandoned'))).toBe(false);
  });
});

describe('emailMessage', () => {
  it('makes one paragraph per item, line breaks as <br>', () => {
    expect(emailMessage(['Hi,', 'See you,\nShop'])).toBe('<p>Hi,</p>\n<p>See you,<br>\nShop</p>');
    expect(emailMessage(['Fish & chips <3 {{ site.name }}'])).toBe('<p>Fish &amp; chips &lt;3 {{ site.name }}</p>');
  });
});
