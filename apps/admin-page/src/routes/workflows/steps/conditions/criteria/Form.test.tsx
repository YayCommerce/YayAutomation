import { act } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { api } from '@/lib/api/api';
import { TooltipProvider } from '@/components/ui/tooltip';
import { steps } from '../..';
import { plainSummary } from '../../core/marks';
import { CriteriaForm } from './Form';
import type { CriteriaConfig, Rule } from './schema';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

vi.mock('@/lib/api/api', () => ({ api: { get: vi.fn() } }));

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

const mount = async (rules: Rule[], onChange = vi.fn()) => {
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
    expect(container.textContent).toContain('Search products');
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

  it('a custom field: its name, then the value', async () => {
    await mount([rule({ field: 'order.meta', operator: 'equal', value: 'yes', key: '_gift' })]);
    const values = [...container.querySelectorAll('input')].map((input) => input.value);
    expect(values).toEqual(['_gift', 'yes']);
    expect(container.querySelector('datalist')).toBeNull();
  });

  it('a customer custom field: suggests the names other workflows use', async () => {
    await mount([rule({ field: 'customer.meta', operator: 'equal', value: '', key: '' })]);
    await settle();
    const options = [...container.querySelectorAll('datalist option')].map((option) => option.getAttribute('value'));
    expect(options).toEqual(['vip', 'vip_since']);
  });

  it('a note on the field shows under its rule', async () => {
    await mount([rule({ field: 'customer.total_spent', operator: 'greater_than', value: '1' })]);
    expect(container.textContent).toContain('added as stored');
  });
});

describe('canvas summaries', () => {
  const summary = (rules: Rule[]) => plainSummary(steps.condition.summary({ match: 'all', rules }));

  it('reads a search list as a count', () => {
    expect(summary([rule({ field: 'order.product_ids', operator: 'matches_any', value: ['1', '2'] })])).toBe(
      'Products in order matches any of 2 selected',
    );
  });

  it('reads a custom field with its name', () => {
    expect(summary([rule({ field: 'customer.meta', operator: 'equal', value: 'yes', key: 'vip' })])).toBe(
      'Custom field vip is equal to yes',
    );
  });

  it('shows the first rule and a count past three', () => {
    const one = rule({ field: 'order.product_ids', operator: 'matches_any', value: ['1', '2'] });
    const other = rule({ field: 'order.total', operator: 'equal', value: '1' });
    expect(summary([one, other, other, other, other])).toBe('Products in order matches any of 2 selected and 4 more');
  });
});
