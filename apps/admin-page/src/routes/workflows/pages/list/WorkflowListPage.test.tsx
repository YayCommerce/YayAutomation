import { act } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot, type Root } from 'react-dom/client';
import { createHashRouter, RouterProvider } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useWorkflowsQuery } from '../../api/workflow.query';
import WorkflowListPage from './WorkflowListPage';

vi.mock('../../api/workflow.query', async (original) => ({
  ...(await original<typeof import('../../api/workflow.query')>()),
  useWorkflowsQuery: vi.fn(),
}));
vi.mock('./WorkflowsTable', () => ({
  WorkflowsTable: () => <table data-testid="workflows" />,
  WorkflowsTableSkeleton: () => <div />,
}));
vi.mock('./library/TemplatesDialog', () => ({ TemplatesDialog: () => null }));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement;
let root: Root;
let router: ReturnType<typeof createHashRouter>;

beforeEach(() => {
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  router.dispose();
  container.remove();
});

async function render(query: Partial<ReturnType<typeof useWorkflowsQuery>>) {
  vi.mocked(useWorkflowsQuery).mockReturnValue(query as ReturnType<typeof useWorkflowsQuery>);
  router = createHashRouter([{ path: '/workflows', element: <WorkflowListPage /> }]);
  await act(() => router.navigate('/workflows'));
  await act(async () =>
    root.render(
      <QueryClientProvider client={new QueryClient()}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    ),
  );
}

const count = (name: string) =>
  [...container.querySelectorAll('button')].filter((b) => b.textContent?.trim() === name).length;

describe('WorkflowListPage', () => {
  it('shows one Browse / Create pair when there are no workflows: the empty state’s', async () => {
    await render({ data: [], error: null });

    expect(count('Create workflow')).toBe(1);
    expect(count('Browse templates')).toBe(1);
    expect(container.textContent).toContain('Automate your store’s routine work');
  });

  it('shows the pair in the title row once there are workflows', async () => {
    await render({ data: [{ id: '1' }] as never, error: null });

    expect(count('Create workflow')).toBe(1);
    expect(count('Browse templates')).toBe(1);
    expect(container.querySelector('[data-testid="workflows"]')).not.toBeNull();
  });

  it('offers no buttons while loading or after an error', async () => {
    await render({ data: undefined, error: null });
    expect(count('Create workflow')).toBe(0);

    await render({ data: undefined, error: new Error('Down') });
    expect(count('Create workflow')).toBe(0);
    expect(container.textContent).toContain('Down');
  });
});
