import type { WorkflowGraph } from '@yaymatik/types';
import { afterEach, describe, expect, it } from 'vitest';

import { structureErrors } from '../model/structure';
import { validateWorkflow } from '../model/validation';
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
