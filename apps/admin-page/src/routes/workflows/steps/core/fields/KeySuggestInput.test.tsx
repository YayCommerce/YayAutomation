import { act, useState } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { api } from '@/lib/api/api';
import { KeySuggestInput } from './KeySuggestInput';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

vi.mock('@/lib/api/api', () => ({ api: { get: vi.fn() } }));

let container: HTMLDivElement;
let root: Root;
let changes: string[];

beforeEach(() => {
  vi.mocked(api.get).mockImplementation((() => ({
    json: () => Promise.resolve({ keys: ['gift_wrap', 'vip', 'vip_since'] }),
  })) as never);
  Element.prototype.scrollIntoView = vi.fn();
  globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as never;
  changes = [];
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

function Field() {
  const [value, setValue] = useState('');
  return (
    <KeySuggestInput
      id="key"
      value={value}
      onChange={(next) => {
        changes.push(next);
        setValue(next);
      }}
    />
  );
}

const settle = () => act(() => new Promise((done) => setTimeout(done, 5)));
const input = () => document.getElementById('key') as HTMLInputElement;
const rows = () => [...document.querySelectorAll('[role="option"]')].map((row) => row.textContent);
const key = (name: string) =>
  act(() => {
    input().dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true }));
  });
const type = (text: string) =>
  act(() => {
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input(), text);
    input().dispatchEvent(new Event('input', { bubbles: true }));
  });

const mount = async () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  act(() =>
    root.render(
      <QueryClientProvider client={client}>
        <Field />
      </QueryClientProvider>,
    ),
  );
  await settle();
  act(() => input().focus());
  await settle();
};

describe('KeySuggestInput', () => {
  it('lists the names other workflows use under the field, with its heading', async () => {
    await mount();
    expect(document.body.textContent).toContain('Used in your workflows');
    expect(rows()).toEqual(['gift_wrap', 'vip', 'vip_since']);
    expect(input().getAttribute('aria-expanded')).toBe('true');
  });

  it('narrows the list as you type, and keeps any name typed', async () => {
    await mount();
    type('vi');
    await settle();
    expect(rows()).toEqual(['vip', 'vip_since']);
    key('Enter');
    expect(changes).toEqual(['vi']);
    expect(rows()).toEqual([]);
  });

  it('picks a name with the arrow keys and Enter', async () => {
    await mount();
    type('vi');
    await settle();
    key('ArrowDown');
    key('ArrowDown');
    key('Enter');
    await settle();
    expect(changes.at(-1)).toBe('vip_since');
    expect(input().value).toBe('vip_since');
  });

  it('closes on Escape', async () => {
    await mount();
    key('Escape');
    await settle();
    expect(rows()).toEqual([]);
  });
});
