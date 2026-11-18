import { act } from 'react';
import { criteriaFeatures } from '@edition/criteria';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { api } from '@/lib/api/api';
import { TooltipProvider } from '@/components/ui/tooltip';
import { steps } from '../..';
import { FieldIssueContext } from '../../core/fields/feedback';
import { plainSummary } from '../../core/marks';
import { CriteriaForm } from './Form';
import type { CriteriaConfig, CriteriaItem, Rule } from './schema';

// Pro features of the form, the schema and the pickers: groups, comparing with another field, custom field rules.
const pro = criteriaFeatures.groups;

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

vi.mock('@/lib/api/api', () => ({ api: { get: vi.fn() } }));

const total = (): Rule => ({ field: 'order.total', operator: 'equal', value: '1', valueType: 'value' });
const buttons = (name: string) => [...container.querySelectorAll('button')].filter((b) => b.textContent === name);
const rule = (over: Partial<Rule>): Rule => ({ field: '', operator: '', value: '', valueType: 'value', ...over });

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  vi.mocked(api.get).mockImplementation(((url: string) => ({
    json: () =>
      Promise.resolve(
        url === 'search/customer-meta-keys' ? { keys: ['vip', 'vip_since'] } : { items: [], hasMore: false },
      ),
  })) as never);
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

const settle = () => act(() => new Promise((done) => setTimeout(done, 5)));

const mount = async (rules: CriteriaItem[], onChange = vi.fn()) => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  act(() =>
    root.render(
      <QueryClientProvider client={client}>
        <TooltipProvider>
          <CriteriaForm config={{ match: 'all', rules } as CriteriaConfig} onChange={onChange} />
        </TooltipProvider>
      </QueryClientProvider>,
    ),
  );
  await settle();
};

describe('each rule draws what its comparison takes', () => {
  it('a search list: names what it searches', async () => {
    await mount([rule({ field: 'order.product_ids', operator: 'matches_any', value: [] })]);
    expect(container.querySelector<HTMLInputElement>('input[role="combobox"]')?.placeholder).toBe('Search products');
  });

  it('a list with options: chips with their labels', async () => {
    await mount([rule({ field: 'order.status', operator: 'is_any_of', value: ['processing'] })]);
    expect(container.textContent).toContain('Processing');
  });

  it('a relative date: an amount and a unit', async () => {
    await mount([rule({ field: 'order.created_date', operator: 'in_last', value: '3', unit: 'hours' })]);
    expect(container.querySelector<HTMLInputElement>('input[type="number"]')?.value).toBe('3');
    expect(container.textContent).toContain('hours');
    expect(container.querySelector('input[type="date"]')).toBeNull();
  });

  it('is set: no value at all', async () => {
    await mount([rule({ field: 'order.created_date', operator: 'is_set' })]);
    expect(container.querySelector('input')).toBeNull();
  });

  it.runIf(pro)('a custom field: its name, then the value', async () => {
    await mount([rule({ field: 'order.meta', operator: 'equal', value: 'yes', key: '_gift' })]);
    const values = [...container.querySelectorAll('input')].map((input) => input.value);
    expect(values).toEqual(['_gift', 'yes']);
    expect(container.querySelector('input[role="combobox"]')).toBeNull();
  });

  it.runIf(pro)('a customer custom field: suggests the names other workflows use', async () => {
    await mount([rule({ field: 'customer.meta', operator: 'equal', value: '', key: '' })]);
    act(() => container.querySelector<HTMLInputElement>('input[role="combobox"]')!.focus());
    await settle();
    expect(document.body.textContent).toContain('Used in your workflows');
    const options = [...document.querySelectorAll('[role="option"]')].map((option) => option.textContent);
    expect(options).toEqual(['vip', 'vip_since']);
  });

  it.runIf(pro)('a note on the field shows under its rule', async () => {
    await mount([rule({ field: 'customer.total_spent', operator: 'greater_than', value: '1' })]);
    expect(container.textContent).toContain('added as stored');
  });
});

it.runIf(!pro)('the free edition offers no group and no comparing with another field', async () => {
  await mount([total()]);
  expect(buttons('Add group')).toHaveLength(0);
  expect(container.querySelectorAll('[aria-label="Compare with other data"]')).toHaveLength(0);
  expect(buttons('Add rule')).toHaveLength(1);
});

describe.runIf(pro)('groups in the form', () => {
  it('a condition with no group looks as it did, with Add group beside Add rule', async () => {
    await mount([total()]);
    expect(buttons('Add group')).toHaveLength(1);
    expect(buttons('Add rule to group')).toHaveLength(0);
  });

  it('adds a group', async () => {
    const onChange = vi.fn();
    await mount([total()], onChange);
    act(() => buttons('Add group')[0].click());
    expect(onChange.mock.lastCall![0].rules).toEqual([
      total(),
      { match: 'all', rules: [{ field: '', operator: '', value: '', valueType: 'value' }] },
    ]);
  });

  it('replaces the blank rule a new condition starts with', async () => {
    const onChange = vi.fn();
    await mount([rule({})], onChange);
    act(() => buttons('Add group')[0].click());
    expect(onChange.mock.lastCall![0].rules).toHaveLength(1);
    expect(onChange.mock.lastCall![0].rules[0].match).toBe('all');
  });

  it('offers no Add group inside a group', async () => {
    await mount([{ match: 'any', rules: [total()] }]);
    expect(buttons('Add rule to group')).toHaveLength(1);
    expect(buttons('Add group')).toHaveLength(1);
  });

  it('removes a group', async () => {
    const onChange = vi.fn();
    await mount([{ match: 'any', rules: [total()] }, total()], onChange);
    act(() => container.querySelector<HTMLButtonElement>('[aria-label="Remove group"]')!.click());
    expect(onChange.mock.lastCall![0].rules).toEqual([total()]);
  });

  it('its last rule can go, leaving an empty group', async () => {
    const onChange = vi.fn();
    await mount([{ match: 'any', rules: [total()] }, total()], onChange);
    act(() => container.querySelector<HTMLButtonElement>('[aria-label="Remove rule"]')!.click());
    expect(onChange.mock.lastCall![0].rules[0]).toEqual({ match: 'any', rules: [] });
  });
});

describe('canvas summaries', () => {
  const summary = (rules: CriteriaItem[], match = 'all') => plainSummary(steps.condition.summary({ match, rules }));

  it('reads a search list as a count', () => {
    expect(summary([rule({ field: 'order.product_ids', operator: 'matches_any', value: ['1', '2'] })])).toBe(
      'Products in order matches any of 2 selected',
    );
  });

  it.runIf(pro)('reads a custom field with its name', () => {
    expect(summary([rule({ field: 'customer.meta', operator: 'equal', value: 'yes', key: 'vip' })])).toBe(
      'Custom field vip is equal to yes',
    );
  });

  it('shows the first rule and a count past three', () => {
    const one = rule({ field: 'order.product_ids', operator: 'matches_any', value: ['1', '2'] });
    const other = rule({ field: 'order.total', operator: 'equal', value: '1' });
    expect(summary([one, other, other, other, other])).toBe('Products in order matches any of 2 selected and 4 more');
  });

  it('wraps a group in brackets, joined by its own match', () => {
    const other = rule({ field: 'order.total', operator: 'less_than', value: '9' });
    expect(summary([{ match: 'any', rules: [total(), other] }, total()])).toBe(
      '(Order total is equal to 1 or Order total is less than 9) and Order total is equal to 1',
    );
  });

  it('shortens a long group as it does a long condition', () => {
    expect(summary([{ match: 'all', rules: [total(), total(), total(), total()] }])).toBe(
      '(Order total is equal to 1 and 3 more)',
    );
  });

  it('says when a group has no rules yet', () => {
    expect(summary([total(), { match: 'all', rules: [] }])).toBe('A group has no rules yet');
  });
});

describe('a server issue that names its rule', () => {
  const protectedKey = rule({ field: 'customer.meta', key: 'wp_capabilities', operator: 'is_not_empty' });
  const message = 'This custom field is protected. Choose another one.';
  const mountWith = async (rules: CriteriaItem[], path: number[]) => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    act(() =>
      root.render(
        <QueryClientProvider client={client}>
          <TooltipProvider>
            <FieldIssueContext.Provider value={{ field: 'rules', rule: path, message }}>
              <CriteriaForm config={{ match: 'all', rules } as CriteriaConfig} onChange={vi.fn()} />
            </FieldIssueContext.Provider>
          </TooltipProvider>
        </QueryClientProvider>,
      ),
    );
    await settle();
  };
  /** The smallest box around this rule's key input that holds the message; it must not hold the other rule. */
  const shownUnder = (keyId: string, otherId: string) => {
    let box = document.getElementById(keyId)?.parentElement ?? null;
    while (box && !box.textContent?.includes(message)) box = box.parentElement;
    return !!box && !box.querySelector(`#${otherId}`);
  };

  it.runIf(pro)('shows under that rule at the top level, once', async () => {
    await mountWith([total(), protectedKey], [1]);
    expect(container.textContent?.split(message).length).toBe(2);
    expect(shownUnder('rule-1-key', 'rule-0-value')).toBe(true);
  });

  it.runIf(pro)('shows under that rule inside a group', async () => {
    await mountWith([total(), { match: 'any', rules: [total(), protectedKey] }], [1, 1]);
    expect(container.textContent?.split(message).length).toBe(2);
    expect(shownUnder('group-1-rule-1-key', 'group-1-rule-0-value')).toBe(true);
  });

  it.runIf(pro)('gives way to the form’s own error there', async () => {
    await mountWith([rule({ field: 'customer.meta', key: '', operator: 'is_not_empty' })], [0]);
    expect(container.textContent).toContain('Enter the custom field name.');
    expect(container.textContent).not.toContain(message);
  });
});
