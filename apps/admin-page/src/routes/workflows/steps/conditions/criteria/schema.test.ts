import { describe, expect, it } from 'vitest';

import { messages } from '../../../model/messages';
import { needsValue } from '../../core/data';
import { criteriaErrors, normalize, type Rule } from './schema';

const rule = (over: Partial<Rule>): Rule => ({
  field: 'order.created_date',
  operator: 'in_last',
  value: '3',
  valueType: 'value',
  ...over,
});
const error = (over: Partial<Rule>) => criteriaErrors([rule(over)])[0];

describe('rule checks, same messages and order as PHP', () => {
  it('needs a whole number for a relative date', () => {
    for (const value of ['', '0', '-1', '1.5', 'abc']) {
      expect(error({ value })).toBe(messages.ruleAmount);
    }
    expect(error({ value: '30', unit: 'days' })).toBeNull();
  });

  it('needs a value from the options for a list comparison', () => {
    const status = { field: 'order.status', operator: 'is_any_of' };
    expect(error({ ...status, value: [] })).toBe(messages.ruleListRequired);
    expect(error({ ...status, value: ['nope'] })).toBe(messages.ruleOption);
    expect(error({ ...status, value: ['processing'] })).toBeNull();
  });

  it('checks the comparison before the value', () => {
    expect(error({ field: 'order.total', operator: 'in_last', value: 'abc' })).toBe(messages.ruleComparison);
  });

  it('needs a custom field name on a custom field rule', () => {
    const meta = { field: 'order.meta', operator: 'equal', value: 'x' };
    expect(error(meta)).toBe(messages.ruleKeyRequired);
    expect(error({ ...meta, key: ' ' })).toBe(messages.ruleKeyRequired);
    expect(error({ ...meta, key: '_vip' })).toBeNull();
  });

  it('needs no value for is set', () => {
    expect(needsValue('is_set')).toBe(false);
    expect(needsValue('is_not_set')).toBe(false);
    expect(error({ operator: 'is_set', value: '' })).toBeNull();
  });
});

describe('normalize, the shape PHP stores', () => {
  const one = (input: Partial<Rule>) => normalize({ rules: [input] }).rules[0];

  it('leaves a plain rule its four keys', () => {
    expect(Object.keys(one({ field: 'order.total', operator: 'equal', value: '1' }))).toEqual([
      'field',
      'operator',
      'value',
      'valueType',
    ]);
  });

  it('keeps a key on a custom field rule only, with no variable', () => {
    expect(one({ field: 'order.meta', operator: 'equal', value: 'x', valueType: 'variable', key: ' _vip ' })).toEqual({
      field: 'order.meta',
      operator: 'equal',
      value: 'x',
      valueType: 'value',
      key: '_vip',
    });
    expect(one({ field: 'order.total', operator: 'equal', value: '1', key: '_vip' }).key).toBeUndefined();
  });

  it('gives a relative date a unit, days when unknown', () => {
    expect(one({ operator: 'in_last', value: '3', unit: 'hours' }).unit).toBe('hours');
    expect(one({ operator: 'in_last', value: '3' }).unit).toBe('days');
    expect(one({ operator: 'equal', value: '3', unit: 'hours' }).unit).toBeUndefined();
  });

  it('keeps a list only on a list comparison, never as a variable', () => {
    expect(one({ operator: 'is_any_of', value: ['a'], valueType: 'variable' })).toMatchObject({
      value: ['a'],
      valueType: 'value',
    });
    expect(one({ operator: 'is_any_of', value: 'a' }).value).toEqual(['a']);
    expect(one({ operator: 'is_any_of', value: ['', ' a ', 'a'] }).value).toEqual(['a']);
    expect(one({ operator: 'equal', value: ['a'] }).value).toBe('');
  });
});
