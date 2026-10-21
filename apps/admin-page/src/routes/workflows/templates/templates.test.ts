import { describe, expect, it } from 'vitest';

import { structureErrors } from '../model/structure';
import { validateWorkflow } from '../model/validation';
import { emailMessage } from './core/email';
import { findTemplate, isComingSoon, sendsCustomerEmail, templates, versionsOf, workflowRank } from './index';

describe('templates', () => {
  it('has templates with unique ids', () => {
    expect(templates.length).toBeGreaterThan(0);
    expect(new Set(templates.map((template) => template.id)).size).toBe(templates.length);
  });

  it.each(templates.map((template) => [template.id, template] as const))(
    '%s saves and can be activated as is',
    (_id, template) => {
      expect(structureErrors(template.graph)).toEqual([]);
      // Coming soon: uses steps or variables PHP doesn't have yet, so Activate would reject it.
      if (!isComingSoon(template.graph)) {
        expect(validateWorkflow({ name: template.name, graph: template.graph })).toEqual([]);
      }
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
    '%s can be used and emails the coupon box on its own paragraph',
    (id) => {
      const { graph } = findTemplate(id)!;
      expect(isComingSoon(graph)).toBe(false);
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

describe('emailMessage', () => {
  it('makes one paragraph per item, line breaks as <br>', () => {
    expect(emailMessage(['Hi,', 'See you,\nShop'])).toBe('<p>Hi,</p>\n<p>See you,<br>\nShop</p>');
    expect(emailMessage(['Fish & chips <3 {{ site.name }}'])).toBe('<p>Fish &amp; chips &lt;3 {{ site.name }}</p>');
  });
});
