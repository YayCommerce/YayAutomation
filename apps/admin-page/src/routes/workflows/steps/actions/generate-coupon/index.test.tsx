import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, describe, expect, it } from 'vitest';

import { generateCouponStep as step } from '.';
import { plainSummary } from '../../core/marks';

// Tells React this is a test, so `act()` flushes updates without warnings.
(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const saved = {
  discount_type: 'percent',
  amount: 10,
  expiry_days: 30,
  minimum_spend: null,
  individual_use: false,
  limit_to_customer: true,
  code_prefix: 'YM',
};

const summary = (config: Record<string, unknown>) => plainSummary(step.summary({ ...saved, ...config }));

describe('Generate coupon config', () => {
  it('takes the numbers PHP saves', () => {
    expect(step.normalize(saved)).toEqual(saved);
    expect(step.firstError(saved)).toBeNull();
    expect(step.firstError({ ...saved, minimum_spend: 25.5, expiry_days: null })).toBeNull();
  });

  it('reads configs saved with numbers as text, blank as none', () => {
    const old = { ...saved, amount: '10', expiry_days: ' 30 ', minimum_spend: '' };
    expect(step.normalize(old)).toEqual(saved);
    expect(step.firstError(old)).toBeNull();
    expect(step.normalize({ ...saved, minimum_spend: '12.5' })).toMatchObject({ minimum_spend: 12.5 });
  });

  it('checks the field ranges PHP sends while editing', () => {
    expect(step.firstError({ ...saved, expiry_days: 0 })).toBe('Enter 1 or more.');
    expect(step.firstError({ ...saved, amount: 0 })).toBe('Enter 0.01 or more.');
    expect(step.firstError({ ...saved, discount_type: 'fixed_cart', amount: -5 })).toBe('Enter 0.01 or more.');
    expect(step.firstError({ ...saved, discount_type: 'fixed_cart', amount: 0.5 })).toBeNull();
    expect(step.firstError({ ...saved, minimum_spend: -1 })).toBe('Enter 0 or more.');
    expect(step.firstError({ ...saved, expiry_days: 1, minimum_spend: 0 })).toBeNull();
  });

  it('starts from PHP defaults and drops unknown keys', () => {
    expect(step.normalize({ junk: 1 })).toEqual({ ...step.defaults() });
    expect(step.defaults()).toMatchObject({ discount_type: 'percent', amount: 10, expiry_days: 30, code_prefix: 'YM' });
  });
});

describe('Generate coupon card', () => {
  it('names the discount and the expiry', () => {
    expect(summary({})).toBe('10% off, expires in 30 days');
    expect(summary({ discount_type: 'fixed_cart', amount: 5 })).toBe('$5 off, expires in 30 days');
    expect(summary({ discount_type: 'free_shipping', amount: null })).toBe('Free shipping, expires in 30 days');
    expect(summary({ expiry_days: null })).toBe('10% off, never expires');
    expect(summary({ expiry_days: 1 })).toBe('10% off, expires in 1 day');
  });

  it('reads old text numbers and shows "Not set" for a missing amount', () => {
    expect(summary({ amount: '15', expiry_days: '' })).toBe('15% off, never expires');
    expect(summary({ amount: null })).toBe('Not set, expires in 30 days');
  });
});

describe('free shipping warning', () => {
  const { wc } = window.yayMatikMeta;
  const mode = wc.freeShippingCoupon;

  afterEach(() => {
    wc.freeShippingCoupon = mode;
  });

  const panelText = (config: Record<string, unknown>) => {
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);
    const Form = step.Form!;
    act(() => root.render(<Form config={{ ...saved, ...config }} onChange={() => {}} />));
    const text = container.textContent ?? '';
    act(() => root.unmount());
    container.remove();
    return text;
  };

  it('links to the shipping settings when no method takes a coupon', () => {
    wc.freeShippingCoupon = '';
    expect(panelText({ discount_type: 'free_shipping' })).toContain('No shipping method takes a free shipping coupon');
    expect(panelText({ discount_type: 'percent' })).not.toContain('free shipping coupon');
  });

  it('mentions the minimum order amount when methods need both', () => {
    wc.freeShippingCoupon = 'coupon_and_minimum';
    expect(panelText({ discount_type: 'free_shipping' })).toContain('minimum order amount');
  });

  it('says nothing when a method takes the coupon', () => {
    wc.freeShippingCoupon = 'coupon';
    const text = panelText({ discount_type: 'free_shipping' });
    expect(text).not.toContain('No shipping method');
    expect(text).not.toContain('minimum order amount');
  });
});
