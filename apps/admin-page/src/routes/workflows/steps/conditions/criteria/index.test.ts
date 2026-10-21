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
