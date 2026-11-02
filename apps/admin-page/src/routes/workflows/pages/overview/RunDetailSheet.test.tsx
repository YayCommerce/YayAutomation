import { act } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { RunDetail } from '@yaymatik/types';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { toast } from '@/components/ui/sonner';
import { ApiError, getRun } from '../../api/workflow.api';
import { RunDetailSheet } from './RunDetailSheet';

vi.mock('@/components/ui/sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('../../api/workflow.api', async (original) => ({
  ...(await original<typeof import('../../api/workflow.api')>()),
  getRun: vi.fn(),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
  vi.mocked(toast.error).mockClear();
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

const run: RunDetail = {
  id: '31',
  workflowId: '7',
  versionId: '2',
  triggerId: 'order_status_changed',
  subject: 'order:12',
  subjectLabel: 'Order #12',
  subjectUrl: 'https://shop.test/wp-admin/post.php?post=12',
  stoppedByOrderUrl: null,
  status: 'completed',
  error: null,
  waitUntil: null,
  parentRunId: null,
  rootRunId: '31',
  depth: 0,
  startedAt: '2026-10-09T15:00:00+00:00',
  finishedAt: '2026-10-09T15:00:02+00:00',
  steps: [
    {
      id: '1',
      runId: '31',
      stepId: 'mail',
      iteration: 0,
      status: 'completed',
      input: {},
      output: { to: 'a@b.com' },
      error: null,
      attempt: 1,
      startedAt: '2026-10-09T15:00:01+00:00',
      finishedAt: '2026-10-09T15:00:02+00:00',
      stepType: 'send_email',
    },
    { ...({} as RunDetail['steps'][number]), id: '2', status: 'completed', input: {}, output: {}, stepType: null },
  ],
};

async function open(runId: string) {
  const onClose = vi.fn();
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  await act(async () =>
    root.render(
      <QueryClientProvider client={client}>
        <RunDetailSheet workflowId="7" runId={runId} onClose={onClose} />
      </QueryClientProvider>,
    ),
  );
  return onClose;
}

describe('RunDetailSheet', () => {
  it('shows the subject, the trigger and each step that ran with its result', async () => {
    vi.mocked(getRun).mockResolvedValue(run);
    const onClose = await open('31');

    const sheet = document.body.querySelector('[data-slot="sheet-content"]')!;
    await vi.waitFor(() => expect(sheet.querySelector('a')?.textContent).toBe('Order #12'));
    const lines = [...sheet.querySelectorAll('li')].map((li) => li.textContent);
    expect(lines).toHaveLength(3);
    expect(lines[0]).toContain('Order status changed');
    expect(lines[1]).toContain('Sent to a@b.com');
    expect(lines[2]).toContain('Removed step');
    expect(onClose).not.toHaveBeenCalled();
  });

  it('says "Run not found." and closes for a missing run or another workflow’s run', async () => {
    vi.mocked(getRun).mockRejectedValue(new ApiError('Run not found.', 404));
    const closeMissing = await open('99');
    await vi.waitFor(() => expect(closeMissing).toHaveBeenCalled());
    expect(toast.error).toHaveBeenCalledWith('Run not found.', expect.anything());

    vi.mocked(toast.error).mockClear();
    vi.mocked(getRun).mockResolvedValue({ ...run, workflowId: '8' });
    const closeOther = await open('31');
    await vi.waitFor(() => expect(closeOther).toHaveBeenCalled());
    expect(toast.error).toHaveBeenCalledWith('Run not found.', expect.anything());
  });
});
