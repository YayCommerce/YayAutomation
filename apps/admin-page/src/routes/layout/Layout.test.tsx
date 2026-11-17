import { act } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot, type Root } from 'react-dom/client';
import { RouterProvider } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { router } from '@/router';

vi.mock('@/routes/workflows/api/workflow.query', async (original) => ({
  ...(await original<typeof import('@/routes/workflows/api/workflow.query')>()),
  // Loading: the pages draw their skeletons, which is enough for the header.
  useWorkflowQuery: () => ({ data: undefined, error: null }),
  useWorkflowsQuery: () => ({ data: [], error: null }),
}));
vi.mock('@/routes/settings/api/settings.api', async (original) => ({
  ...(await original<typeof import('@/routes/settings/api/settings.api')>()),
  getSettings: vi.fn(async () => ({
    cartTracking: false,
    abandonMinutes: 60,
    captureGuestEmail: true,
    cartRetentionDays: 60,
    runRetentionDays: 90,
    unsubscribeText: '',
    unsubscribeDefault: '',
  })),
}));
vi.mock('@/routes/settings/api/health.api', () => ({
  getHealthChecks: vi.fn(async () => ({ checks: [], extensions: [], checkedAt: new Date().toISOString() })),
  fixHealthCheck: vi.fn(),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

async function renderAt(path: string) {
  await act(() => router.navigate(path));
  await act(async () =>
    root.render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    ),
  );
  await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
}

const headerButtons = () =>
  [...(document.getElementById('yay-header-actions')?.querySelectorAll('button') ?? [])].map((b) =>
    b.textContent?.trim(),
  );

describe('app header', () => {
  it('shows Create on the overview, the editor and a template preview', async () => {
    await renderAt('/workflows/7');
    expect(headerButtons()).toEqual(['Create workflowCreate']);
    await renderAt('/workflows/7/edit');
    expect(headerButtons()).toEqual(['Create workflowCreate']);
    await renderAt('/workflows/templates/first-order-thank-you-discount');
    expect(headerButtons()).toEqual(['Create workflowCreate']);
  });

  it('shows nothing on the list, which has its own buttons', async () => {
    await renderAt('/workflows');
    expect(headerButtons()).toEqual([]);
  });

  it('shows Save changes on Settings', async () => {
    await renderAt('/settings');
    expect(headerButtons()).toEqual(['Save changesSave']);
  });

  it('opens the New workflow dialog from the header', async () => {
    await renderAt('/workflows/7');
    const create = document.getElementById('yay-header-actions')?.querySelector('button') as HTMLButtonElement;
    await act(async () => create.click());
    expect(document.body.textContent).toContain('What do you want to automate?');
  });
});

describe('theme', () => {
  it('is light, even with a stored dark choice and a dark system theme', async () => {
    localStorage.setItem('yay-ui-theme', 'dark');
    const matchMedia = vi.spyOn(window, 'matchMedia').mockImplementation(
      (query: string) =>
        ({
          matches: query.includes('dark'),
          media: query,
          addEventListener: () => {},
          removeEventListener: () => {},
        }) as unknown as MediaQueryList,
    );
    await import('@/main');
    await renderAt('/workflows');
    const root = document.documentElement.classList;
    expect(root.contains('yay-light')).toBe(true);
    expect(root.contains('yay-dark')).toBe(false);
    matchMedia.mockRestore();
    localStorage.removeItem('yay-ui-theme');
  });
});
