import { describe, expect, it } from 'vitest';

import { customerLoggedInStep } from '../customer-logged-in';
import { userLoggedInStep } from '../user-logged-in';

const user = userLoggedInStep();
const customer = customerLoggedInStep();

describe('Login triggers', () => {
  it('start on sign-in and return, once every 7 days, in the Customers section', () => {
    expect(customer.defaults()).toEqual({ when: ['sign_in', 'return'], days: 7 });
    expect(user.defaults()).toEqual({ when: ['sign_in', 'return'], days: 7, roles: ['customer'] });
    expect(customer.firstError(customer.defaults())).toBeNull();
    expect(user.firstError(user.defaults())).toBeNull();
    expect([user.category, customer.category]).toEqual(['customer', 'customer']);
  });

  it('need at least one moment to trigger on, from the list', () => {
    for (const step of [user, customer]) {
      expect(step.firstError({ ...step.defaults(), when: [] })).not.toBeNull();
      expect(step.firstError({ ...step.defaults(), when: ['auto'] })).toBeNull();
      expect(step.firstError({ ...step.defaults(), when: ['logout'] })).not.toBeNull();
    }
  });

  it('take 1 to 365 days, whole like PHP sanitize(), and keep a blank for the required message', () => {
    for (const step of [user, customer]) {
      expect(step.firstError({ ...step.defaults(), days: 1 })).toBeNull();
      expect(step.firstError({ ...step.defaults(), days: 365 })).toBeNull();
      expect(step.firstError({ ...step.defaults(), days: 0 })).toBe('Enter 1 or more.');
      expect(step.firstError({ ...step.defaults(), days: 366 })).toBe('Enter 365 or less.');
      expect(step.firstError({ ...step.defaults(), days: null })).not.toBeNull();
      expect(step.normalize({ days: 6.5, junk: 1 })).toEqual({ ...step.defaults(), days: 7 });
      expect(step.normalize({ days: null })).toEqual({ ...step.defaults(), days: null });
    }
  });

  it('roles: only User logged in has them, and empty means all roles', () => {
    expect(customer.normalize({ roles: ['editor'] })).not.toHaveProperty('roles');
    expect(user.firstError({ ...user.defaults(), roles: [] })).toBeNull();
    expect(user.firstError({ ...user.defaults(), roles: ['nope'] })).not.toBeNull();
  });

  it('offer the trigger time as a date to compare with', () => {
    expect(window.yayMatikMeta.automation.steps.customer_logged_in?.facts).toEqual([
      { key: 'trigger.time', label: 'Trigger time', type: 'date', valueType: 'date', conditional: true },
    ]);
  });
});
