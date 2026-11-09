import { act } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { api } from '@/lib/api/api';
import { TooltipProvider } from '@/components/ui/tooltip';
import { steps } from '../..';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

vi.mock('@/lib/api/api', () => ({ api: { get: vi.fn() } }));

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  vi.mocked(api.get).mockImplementation(((url: string) => ({
    json: () => Promise.resolve(url === 'search/customer-meta-keys' ? { keys: ['vip', 'vip_since'] } : {}),
  })) as never);
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

describe('Update customer custom field panel', () => {
  it('suggests the names other workflows use, and says guests are skipped', async () => {
    const step = steps.update_customer_meta;
    const Form = step.Form!;
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    act(() =>
      root.render(
        <QueryClientProvider client={client}>
          <TooltipProvider>
            <Form config={step.defaults()} onChange={vi.fn()} />
          </TooltipProvider>
        </QueryClientProvider>,
      ),
    );
    await act(() => new Promise((done) => setTimeout(done, 5)));

    const input = container.querySelector<HTMLInputElement>('#step-field-key')!;
    expect(input.getAttribute('role')).toBe('combobox');
    act(() => input.focus());
    await act(() => new Promise((done) => setTimeout(done, 5)));
    expect(document.body.textContent).toContain('Used in your workflows');
    expect([...document.querySelectorAll('[role="option"]')].map((o) => o.textContent)).toEqual(['vip', 'vip_since']);
    expect(container.querySelector('#step-field-value')?.getAttribute('role')).toBeNull();
    expect(container.textContent).toContain('Customers without an account are skipped.');
  });
});
