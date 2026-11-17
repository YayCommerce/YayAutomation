import { describe, expect, it } from 'vitest';

import { messages } from '../../../model/messages';
import { needsValue } from '../../core/data';
import { criteriaErrors, flatRules, normalize, ruleForField, withOperator, type Group, type Rule } from './schema';

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
  const one = (input: Partial<Rule>) => normalize({ rules: [input] }).rules[0] as Rule;

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

describe('changing a comparison', () => {
  it('keeps the value while the shape is the same', () => {
    expect(withOperator(rule({ operator: 'in_last', value: '3', unit: 'hours' }), 'not_in_last')).toMatchObject({
      value: '3',
      unit: 'hours',
    });
    expect(withOperator(rule({ operator: 'before', value: '2026-01-01' }), 'after').value).toBe('2026-01-01');
  });

  it('clears it when the shape changes', () => {
    expect(withOperator(rule({ operator: 'before', value: '2026-01-01' }), 'in_last')).toMatchObject({
      value: '',
      unit: 'days',
    });
    const single = withOperator(rule({ operator: 'in_last', value: '3', unit: 'hours' }), 'before');
    expect(single.value).toBe('');
    expect(single.unit).toBeUndefined();
    expect(withOperator(rule({ field: 'order.status', operator: 'is', value: 'x' }), 'is_any_of').value).toEqual([]);
  });
});

describe('changing a rule’s field', () => {
  it('keeps value and unit on a field of the same type', () => {
    const next = ruleForField(rule({ operator: 'in_last', value: '3', unit: 'hours' }), 'order.paid_date', null);
    expect(next).toMatchObject({ field: 'order.paid_date', operator: 'in_last', value: '3', unit: 'hours' });
  });

  it('clears a list moved to another kind of search', () => {
    const next = ruleForField(
      rule({ field: 'order.product_ids', operator: 'matches_any', value: ['4'] }),
      'order.category_ids',
      null,
    );
    expect(next).toMatchObject({ operator: 'matches_any', value: [] });
    const same = ruleForField(
      rule({ field: 'order.product_ids', operator: 'matches_any', value: ['4'] }),
      'customer.purchased_product_ids',
      null,
    );
    expect(same.value).toEqual(['4']);
  });

  it('clears a custom field name moved between order and customer', () => {
    const next = ruleForField(
      rule({ field: 'order.meta', operator: 'equal', value: 'yes', key: '_gift' }),
      'customer.meta',
      null,
    );
    expect(next.key).toBe('');
  });

  it('starts another type with its first comparison, in its shape', () => {
    const next = ruleForField(rule({ operator: 'in_last', value: '3', unit: 'hours' }), 'order.product_ids', null);
    expect(next).toEqual({ field: 'order.product_ids', operator: 'matches_any', value: [], valueType: 'value' });
    expect(ruleForField(rule({ field: 'order.total', operator: 'equal', value: '1' }), 'order.meta', null)).toEqual({
      field: 'order.meta',
      operator: 'equal',
      value: '',
      valueType: 'value',
      key: '',
    });
  });
});

describe('groups', () => {
  const good = rule({ field: 'order.total', operator: 'equal', value: '1' });
  const group = (rules: Rule[], match: Group['match'] = 'all'): Group => ({ match, rules });

  it('gives errors per rule inside a group', () => {
    const bad = rule({ field: 'order.total', operator: 'equal', value: 'abc' });
    expect(criteriaErrors([group([good, bad])])).toEqual([{ own: null, rules: [null, messages.ruleNumber] }]);
  });

  it('says an empty group needs a rule, as PHP does', () => {
    expect(criteriaErrors([good, group([])])).toEqual([null, { own: messages.groupRulesRequired, rules: [] }]);
    expect(criteriaErrors([good, group([rule({ field: '' })])])).toEqual([
      null,
      { own: messages.groupRulesRequired, rules: [null] },
    ]);
  });

  it('puts the required message on the first item when nothing is chosen', () => {
    expect(criteriaErrors([group([rule({ field: '' })])])).toEqual([{ own: messages.rulesRequired, rules: [null] }]);
  });

  it('flattens for what reads every rule', () => {
    expect(flatRules([good, group([good, good])])).toHaveLength(3);
  });

  it('normalizes one level deep, and leaves a flat rule alone', () => {
    const config = normalize({
      rules: [{ match: 'any', rules: ['x', good, { match: 'all', rules: [good] }] }, good],
    });
    expect(config.rules[0]).toEqual({ match: 'any', rules: [good] });
    expect(config.rules[1]).toEqual(good);
  });
});
