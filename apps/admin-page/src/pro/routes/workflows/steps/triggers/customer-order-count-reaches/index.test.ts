import { describe, expect, it } from 'vitest';

import { customerOrderCountReachesStep } from '.';
import { customerTotalSpendReachesStep } from '../customer-total-spend-reaches';

const count = customerOrderCountReachesStep();
const spend = customerTotalSpendReachesStep();

describe('Customer milestones', () => {
  it('order count starts at 5 paid orders, a whole number of 1 or more, in the Customers section', () => {
    expect(count.defaults()).toEqual({ count: 5 });
    expect(count.firstError(count.defaults())).toBeNull();
    expect(count.firstError({ count: 0 })).toBe('Enter 1 or more.');
    expect(count.normalize({ count: 2.6 })).toEqual({ count: 3 });
    expect(count.normalize({ count: null })).toEqual({ count: null });
    expect(count.category).toBe('customer');
  });

  it('total spend needs an amount above 0', () => {
    expect(spend.firstError(spend.defaults())).not.toBeNull();
    expect(spend.firstError({ amount: 0 })).toBe('Enter 0.01 or more.');
    expect(spend.firstError({ amount: 1000 })).toBeNull();
    expect(spend.category).toBe('customer');
  });
});
