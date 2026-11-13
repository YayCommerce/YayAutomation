import { act } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { RunDetail, StepId } from '@yaymatik/types';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { toast } from '@/components/ui/sonner';
import { ApiError, getRun } from '../../api/workflow.api';
import { RunDetailSheet } from './RunDetailSheet';

// The registry reads the steps of plugins that are off when it loads.
vi.hoisted(() => {
  const automation = window.yayMatikMeta.automation;
  const acme = { module: 'acme', plugin: 'Acme Rewards', outputs: [], facts: [] };
  window.yayMatikMeta = {
    ...window.yayMatikMeta,
    automation: {
      ...automation,
      inactiveSteps: {
        acme_add_points: { ...acme, kind: 'action', label: 'Add reward points' },
        acme_points_earned: { ...acme, kind: 'trigger', label: 'Points earned' },
      },
    },
  } as unknown as Window['yayMatikMeta'];
});

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
  facts: [],
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

  it('shows the trigger facts under the trigger', async () => {
    vi.mocked(getRun).mockResolvedValue({
      ...run,
      facts: [
        { label: 'Points earned', value: '42' },
        { label: 'Tier', value: 'Gold' },
      ],
    });
    await open('31');
    const sheet = document.body.querySelector('[data-slot="sheet-content"]')!;
    await vi.waitFor(() => expect(sheet.querySelector('li')?.textContent).toContain('Points earned: 42, Tier: Gold'));
  });

  it('names a trigger and an action of a plugin that is off, greyed like the canvas', async () => {
    vi.mocked(getRun).mockResolvedValue({
      ...run,
      triggerId: 'acme_points_earned',
      steps: [{ ...run.steps[0], stepType: 'acme_add_points' as StepId, output: {} }],
    });
    await open('31');
    const sheet = document.body.querySelector('[data-slot="sheet-content"]')!;
    await vi.waitFor(() => expect(sheet.querySelectorAll('li')).toHaveLength(2));
    const [trigger, action] = sheet.querySelectorAll('li');
    expect(trigger.textContent).toContain('Points earned');
    expect(action.textContent).toContain('Add reward points');
    expect(sheet.textContent).not.toContain('Removed step');
    expect(trigger.querySelector('[aria-hidden]')?.className).toContain('grayscale');
    expect(action.querySelector('[aria-hidden]')?.className).toContain('grayscale');
  });

  it('says "Removed step" for a step id no plugin knows', async () => {
    vi.mocked(getRun).mockResolvedValue({
      ...run,
      steps: [{ ...run.steps[0], stepType: 'gone_step' as StepId, output: {} }],
    });
    await open('31');
    const sheet = document.body.querySelector('[data-slot="sheet-content"]')!;
    await vi.waitFor(() => expect(sheet.querySelectorAll('li')).toHaveLength(2));
    expect(sheet.querySelectorAll('li')[1].textContent).toContain('Removed step');
  });

  it('links the order that stopped a cart run', async () => {
    const viewOrder = (sheet: Element) =>
      [...sheet.querySelectorAll('a')].find((link) => link.textContent === 'View order');
    const orderUrl = 'https://shop.test/wp-admin/admin.php?page=wc-orders&id=52';
    vi.mocked(getRun).mockResolvedValue({
      ...run,
      subjectLabel: 'a@b.com',
      subjectUrl: null,
      status: 'cancelled',
      error: 'The shopper placed order #52.',
      stoppedByOrderUrl: orderUrl,
    });
    await open('31');
    const sheet = document.body.querySelector('[data-slot="sheet-content"]')!;
    await vi.waitFor(() => expect(viewOrder(sheet)?.getAttribute('href')).toBe(orderUrl));
    expect(sheet.textContent).toContain('The shopper placed order #52.');
  });

  it('shows no order link on a run no order stopped', async () => {
    vi.mocked(getRun).mockResolvedValue(run);
    await open('31');
    const sheet = document.body.querySelector('[data-slot="sheet-content"]')!;
    await vi.waitFor(() => expect(sheet.querySelector('a')?.textContent).toBe('Order #12'));
    expect(sheet.textContent).not.toContain('View order');
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
