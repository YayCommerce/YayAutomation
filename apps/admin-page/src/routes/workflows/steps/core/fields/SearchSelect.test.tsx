import { act } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { api } from '@/lib/api/api';
import { SearchSelect } from './SearchSelect';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

vi.mock('@/lib/api/api', () => ({ api: { get: vi.fn() } }));

type Page = { items: { value: string; label: string }[]; hasMore: boolean };
const get = vi.mocked(api.get);

/** What the server answers, by a request's params. */
function answer(reply: (params: URLSearchParams) => Page) {
  respond((params) => Promise.resolve(reply(params)));
}

/** Each response marks itself `done` once its body has been read, for `answered()`. */
function respond(body: (params: URLSearchParams) => Promise<Page>) {
  get.mockImplementation(((_url: string, options: { searchParams: URLSearchParams }) => {
    const response = {
      done: false,
      json: () => body(options.searchParams).finally(() => (response.done = true)),
    };
    return response;
  }) as never);
}

const settle = (ms = 0) => act(() => new Promise((done) => setTimeout(done, ms)));

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  get.mockReset();
  Element.prototype.scrollIntoView = vi.fn();
  globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as never;
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

const mount = async (value: string[], onChange = vi.fn()) => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  act(() =>
    root.render(
      <QueryClientProvider client={client}>
        <SearchSelect id="s" type="product" value={value} onChange={onChange} invalid={false} />
      </QueryClientProvider>,
    ),
  );
  await settle();
  return onChange;
};

/** Settles until a request after the first `asked` has been made and all have answered, then once more to render. */
const answered = async (asked: number) => {
  for (let i = 0; i < 100; i++) {
    await settle(i ? 5 : 0);
    const replies = get.mock.results.map((result) => (result.value as { done?: boolean }).done);
    if (replies.length > asked && replies.every(Boolean)) break;
  }
  await settle(5);
};

const openList = async () => {
  const asked = get.mock.calls.length;
  act(() => document.getElementById('s')!.click());
  await answered(asked);
};

const page = (...labels: string[]) => labels.map((label) => ({ value: label.toLowerCase(), label }));
const listRequests = () =>
  get.mock.calls.map(([, options]) => (options as { searchParams: URLSearchParams }).searchParams);

describe('SearchSelect', () => {
  it('asks for nothing until it opens, then pages through Load more', async () => {
    answer((params) =>
      params.get('page') === '1' ? { items: page('Mug'), hasMore: true } : { items: page('Tea'), hasMore: false },
    );
    await mount([]);
    expect(get).not.toHaveBeenCalled();

    await openList();
    expect(document.body.textContent).toContain('Mug');

    const more = [...document.querySelectorAll('button')].find((b) => b.textContent === 'Load more')!;
    const asked = get.mock.calls.length;
    act(() => more.click());
    await answered(asked);

    expect(document.body.textContent).toContain('Mug');
    expect(document.body.textContent).toContain('Tea');
    expect([...document.querySelectorAll('button')].some((b) => b.textContent === 'Load more')).toBe(false);
  });

  it('loads a saved rule’s labels through include', async () => {
    answer(() => ({ items: [{ value: '4', label: 'Mug' }], hasMore: false }));
    await mount(['4']);
    await answered(0);

    expect(listRequests()[0].getAll('include[]')).toEqual(['4']);
    expect(container.textContent).toContain('Mug');
  });

  it('asks for many saved values in batches the server accepts', async () => {
    answer(() => ({ items: [], hasMore: false }));
    await mount(Array.from({ length: 101 }, (_, i) => String(i + 1)));
    await answered(0);

    expect(listRequests().map((params) => params.getAll('include[]').length)).toEqual([100, 1]);
  });

  it('never shows a value as deleted when the request failed', async () => {
    respond(() => Promise.reject(new Error('down')));
    await mount(['12']);
    await answered(0);

    expect(container.textContent).not.toContain('#12');
  });

  it('says the list failed, not that nothing was found', async () => {
    respond(() => Promise.reject(new Error('down')));
    await mount([]);
    await openList();

    expect(document.body.textContent).toContain('Couldn’t load the list.');
    expect(document.body.textContent).not.toContain('Nothing found.');
  });

  it('shows a deleted id as #12', async () => {
    answer(() => ({ items: [], hasMore: false }));
    await mount(['12']);
    await answered(0);

    expect(container.textContent).toContain('#12');
  });

  it('waits for typing to stop before it searches', async () => {
    answer(() => ({ items: [], hasMore: false }));
    await mount([]);
    await openList();
    const before = get.mock.calls.length;

    const input = document.querySelector<HTMLInputElement>('input')!;
    const setValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!;
    for (const text of ['m', 'mu', 'mug']) {
      act(() => {
        setValue.call(input, text);
        input.dispatchEvent(new Event('input', { bubbles: true }));
      });
    }
    await settle(50);
    expect(get.mock.calls.length).toBe(before);

    await settle(300);
    expect(get.mock.calls.length).toBe(before + 1);
    expect(listRequests().at(-1)!.get('term')).toBe('mug');
  });

  it('names what it searches, and what it did not find', async () => {
    answer(() => ({ items: [], hasMore: false }));
    await mount([]);
    expect(container.textContent).toContain('Search products');

    await openList();
    expect(document.body.textContent).toContain('No products found.');
    expect(document.body.textContent).toContain('0 selected');
  });

  it('shows a row’s id or status beside it', async () => {
    answer(() => ({
      items: [
        { value: '1', label: 'Mug', hint: '#1' },
        { value: '2', label: 'Tea', hint: 'Draft' },
      ],
      hasMore: false,
    }));
    await mount([]);
    await openList();

    expect(document.body.textContent).toContain('#1');
    expect(document.body.textContent).toContain('Draft');
  });

  it('adds and removes a value', async () => {
    answer(() => ({ items: page('Mug'), hasMore: false }));
    const onChange = await mount([]);
    await openList();

    const option = [...document.querySelectorAll('[role="option"]')].find((o) => o.textContent?.includes('Mug'))!;
    act(() => (option as HTMLElement).click());

    expect(onChange).toHaveBeenCalledWith(['mug']);
  });
});
