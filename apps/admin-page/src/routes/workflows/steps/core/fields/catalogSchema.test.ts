import * as v from 'valibot';
import { describe, expect, it } from 'vitest';

import type { StepFieldMeta } from '@/types/localize';
import { defaultsFromFields, schemaFromFields } from './catalogSchema';

const fields: StepFieldMeta[] = [
  {
    key: 'type',
    type: 'select',
    label: 'Type',
    default: 'a',
    options: [
      { value: 'a', label: 'A' },
      { value: 'b', label: 'B' },
    ],
  },
  {
    key: 'note',
    type: 'textarea',
    label: 'Note',
    required: true,
    requiredMessage: '%s needs a note.',
    variables: true,
    default: '',
  },
  { key: 'to', type: 'emails', label: 'To', variables: true, default: '' },
  { key: 'tags', type: 'multiselect', label: 'Tags', default: [], options: [{ value: 'x', label: 'X' }] },
  { key: 'days', type: 'number', label: 'Days', min: 1, max: 30, default: null },
  { key: 'extra', type: 'text', label: 'Extra', required: true, showIf: { field: 'type', value: ['b'] }, default: '' },
  { key: 'on', type: 'toggle', label: 'On', required: true, default: false },
  { key: 'from', type: 'notice', label: 'From', text: 'Store <store@example.test>' },
];

const schema = schemaFromFields(fields, 'Add order note');
const valid = { ...defaultsFromFields(fields), note: 'Hi {{ order.number }}' };

/** Each issue as `field: message`. */
const issues = (config: Record<string, unknown>) => {
  const result = v.safeParse(schema, config);
  return result.success ? [] : result.issues.map((issue) => `${issue.path?.[0]?.key}: ${issue.message}`);
};

describe('schemaFromFields', () => {
  it('defaults hold every stored field, not the notice', () => {
    expect(defaultsFromFields(fields)).toEqual({
      type: 'a',
      note: '',
      to: '',
      tags: [],
      days: null,
      extra: '',
      on: false,
    });
  });

  it('accepts a valid config; a toggle is never blank', () => {
    expect(issues(valid)).toEqual([]);
  });

  it("gives PHP Field::validate()'s messages, each on its field, in field order", () => {
    expect(issues({ ...valid, type: 'c', note: '  ', to: 'a@b.co, nope', tags: ['y'], days: 0 })).toEqual([
      'type: Choose an option from the list.',
      'note: Add order note needs a note.',
      'to: nope isn’t a valid email address.',
      'tags: Choose options from the list.',
      'days: Enter 1 or more.',
    ]);
    expect(issues({ ...valid, days: 31 })).toEqual(['days: Enter 30 or less.']);
  });

  it('skips a field hidden by showIf, and checks it once shown', () => {
    expect(issues({ ...valid, type: 'a', extra: '' })).toEqual([]);
    expect(issues({ ...valid, type: 'b', extra: '' })).toEqual(['extra: This field is required.']);
  });

  it('checks number_unit and rules like PHP: blank value, range, rules without a variable', () => {
    const custom = schemaFromFields(
      [
        { key: 'delay', type: 'number_unit', label: 'Delay', required: true, min: 1 },
        { key: 'rules', type: 'rules', label: 'Rules', required: true },
      ],
      'Wait',
    );
    const messages = (config: Record<string, unknown>) => {
      const result = v.safeParse(custom, config);
      return result.success ? [] : result.issues.map((issue) => issue.message);
    };
    expect(messages({ delay: { value: null, unit: 'days' }, rules: [{ field: '' }] })).toEqual([
      'This field is required.',
      'This field is required.',
    ]);
    expect(messages({ delay: { value: 0, unit: 'days' }, rules: [{ field: 'order.total' }] })).toEqual([
      'Enter 1 or more.',
    ]);
  });

  it('lets an address with a merge tag through when the field takes them', () => {
    expect(issues({ ...valid, to: '{{ customer.email }}' })).toEqual([]);
  });

  it('checks merge tags only once the field rules pass', () => {
    const badTag = { ...valid, note: 'Hi {{ order.nope }}' };
    expect(issues(badTag)).toEqual(['note: {{ order.nope }} isn’t an available variable.']);
    expect(issues({ ...badTag, type: 'c' })).toEqual(['type: Choose an option from the list.']);
  });
});

describe('a rules field', () => {
  const rulesSchema = schemaFromFields(
    [{ key: 'rules', type: 'rules', label: 'Rules', required: true } as StepFieldMeta],
    'Condition',
  );
  const passes = (rules: unknown[]) => v.safeParse(rulesSchema, { rules }).success;

  it('holding only groups is not blank while a group has a started rule', () => {
    expect(passes([{ match: 'all', rules: [{ field: 'order.total' }] }])).toBe(true);
    expect(passes([{ match: 'all', rules: [{ field: '' }] }])).toBe(false);
    expect(passes([{ field: '' }])).toBe(false);
  });
});
