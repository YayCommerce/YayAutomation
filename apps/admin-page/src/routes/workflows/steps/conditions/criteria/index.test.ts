import { describe, expect, it } from 'vitest';

import { steps } from '../..';
import { operatorsFor } from '../../core/data';
import { plainSummary } from '../../core/marks';

const condition = steps.condition;
const rule = (field: string, operator: string) => ({ field, operator, value: '', valueType: 'value' });

describe('yes/no rules', () => {
  it('offer Is true and Is false only', () => {
    expect(operatorsFor('boolean')).toEqual(['is_true', 'is_false']);
  });

  it('need no value', () => {
    const config = {
      match: 'all',
      rules: [rule('customer.is_first_order', 'is_true'), rule('customer.unsubscribed', 'is_false')],
    };
    expect(condition.firstError(config)).toBeNull();
    expect(plainSummary(condition.summary(config))).toBe(
      'Customer’s first order is true and Customer unsubscribed is false',
    );
  });
});

describe('summaries of the new rules', () => {
  const summary = (added: Record<string, unknown>) =>
    plainSummary(condition.summary({ match: 'all', rules: [{ valueType: 'value', value: '', ...added }] }));

  it('reads a relative date with its unit', () => {
    expect(summary({ field: 'order.created_date', operator: 'in_last', value: '3', unit: 'days' })).toBe(
      'Order date is in the last 3 days',
    );
    expect(summary({ field: 'order.created_date', operator: 'not_in_last', value: '1', unit: 'hours' })).toBe(
      'Order date is not in the last 1 hour',
    );
  });

  it('shows no amount until it is a whole number', () => {
    const text = summary({ field: 'order.created_date', operator: 'in_last', value: '2026-01-01', unit: 'days' });
    expect(text).not.toContain('NaN');
    expect(text).not.toContain('2026');
  });

  it('reads is set without a value', () => {
    expect(summary({ field: 'order.created_date', operator: 'is_set' })).toBe('Order date is set');
  });

  it('names the options of a list comparison', () => {
    const text = summary({ field: 'order.status', operator: 'is_any_of', value: ['processing', 'completed'] });
    expect(text).toBe('Order status is any of Processing, Completed');
  });
});
