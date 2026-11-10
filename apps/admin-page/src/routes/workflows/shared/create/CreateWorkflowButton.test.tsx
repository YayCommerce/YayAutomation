import { act } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot, type Root } from 'react-dom/client';
import { createHashRouter, RouterProvider } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { toast } from '@/components/ui/sonner';
import { createWorkflow } from '../../api/workflow.api';
import { CreateWorkflowButton } from './CreateWorkflowButton';

vi.mock('@/components/ui/sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('../../api/workflow.api', async (original) => ({
  ...(await original<typeof import('../../api/workflow.api')>()),
  createWorkflow: vi.fn(),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement;
let root: Root;
let router: ReturnType<typeof createHashRouter>;

beforeEach(() => {
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
  vi.mocked(createWorkflow).mockReset();
  vi.mocked(createWorkflow).mockResolvedValue({ id: '42' } as Awaited<ReturnType<typeof createWorkflow>>);
  vi.mocked(toast.error).mockClear();
});

afterEach(() => {
  act(() => root.unmount());
  router.dispose();
  container.remove();
});

async function chooseTrigger(beforeCreate?: () => Promise<boolean>) {
  router = createHashRouter([
    { path: '/workflows/7/edit', element: <CreateWorkflowButton beforeCreate={beforeCreate} /> },
    { path: '/workflows/42/edit', element: <p id="new-editor" /> },
  ]);
  await act(() => router.navigate('/workflows/7/edit'));
  await act(async () =>
    root.render(
      <QueryClientProvider client={new QueryClient()}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    ),
  );
  await act(async () => container.querySelector('button')?.click());
  const choose = [...document.querySelectorAll('button')].find((b) => b.textContent?.includes('Choose a trigger'));
  await act(async () => choose?.click());
  await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
}

describe('CreateWorkflowButton', () => {
  it('saves first, then creates and opens the new workflow', async () => {
    const order: string[] = [];
    vi.mocked(createWorkflow).mockImplementation(async () => {
      order.push('create');
      return { id: '42' } as Awaited<ReturnType<typeof createWorkflow>>;
    });
    await chooseTrigger(async () => {
      order.push('save');
      return true;
    });
    expect(order).toEqual(['save', 'create']);
    expect(document.getElementById('new-editor')).not.toBeNull();
  });

  it('creates nothing when the save fails', async () => {
    await chooseTrigger(async () => false);
    expect(createWorkflow).not.toHaveBeenCalled();
    expect(toast.error).toHaveBeenCalledOnce();
    expect(document.getElementById('new-editor')).toBeNull();
  });
});
