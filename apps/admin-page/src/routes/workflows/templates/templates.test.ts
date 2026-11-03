import type { WorkflowGraph } from '@yaymatik/types';
import { afterEach, describe, expect, it } from 'vitest';

import { structureErrors } from '../model/structure';
import { validateWorkflow } from '../model/validation';
import type { WaitConfig } from '../steps/actions/wait/schema';
import { emailMessage } from './core/email';
import { findTemplate, needsCartTracking, sendsCustomerEmail, templates, versionsOf, workflowRank } from './index';

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

  it('staff-only templates send no customer email', () => {
    expect(sendsCustomerEmail(findTemplate('high-value-order-alert')!.graph)).toBe(false);
  });

  it('high-value alert fires at 500 or more when the order starts processing', () => {
    const { nodes } = findTemplate('high-value-order-alert')!.graph;
    expect(nodes.map((node) => node.data.block)).toEqual(['order_status_changed', 'condition', 'send_internal_email']);
    expect(nodes[0].data.config).toEqual({
      from: ['pending', 'on-hold', 'failed'],
      to: ['processing'],
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
