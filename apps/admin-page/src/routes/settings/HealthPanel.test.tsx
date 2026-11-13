import { act } from 'react';
import { focusManager, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { HealthCheck, HealthChecksResponse, HealthExtension, HealthFixResponse } from '@yaymatik/types';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { toast } from '@/components/ui/sonner';
import { fixHealthCheck, getHealthChecks } from './api/health.api';
import { HealthPanel, MIN_CHECK_MS } from './HealthPanel';

vi.mock('@/components/ui/sonner', () => ({ toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock('./api/health.api', () => ({ getHealthChecks: vi.fn(), fixHealthCheck: vi.fn() }));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const GUIDE = 'https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/';
const ok = (id: HealthCheck['id']): HealthCheck => ({ id, status: 'ok', count: 0, runIds: [], fix: null });
const queued = (count: number, capped = false): HealthCheck => ({
  id: 'runs_queued',
  status: 'problem',
  count,
  runIds: [1],
  fix: 'enqueue',
  ...(capped ? { capped } : {}),
});
const running = (count: number): HealthCheck => ({
  id: 'runs_running',
  status: 'problem',
  count,
  runIds: [2],
  fix: 'recover',
});
const cleanup: HealthCheck = { id: 'cleanup_job', status: 'problem', count: 1, runIds: [], fix: 'schedule' };
const sweep: HealthCheck = { id: 'inactive_sweep_job', status: 'problem', count: 1, runIds: [], fix: 'schedule' };
const late = (count: number): HealthCheck => ({
  id: 'jobs_late',
  status: 'info',
  count,
  runIds: [],
  fix: null,
  guide: GUIDE,
});
const allOk = [ok('runs_queued'), ok('runs_running'), ok('jobs_late'), ok('cleanup_job'), ok('inactive_sweep_job')];
const scan = (checks: HealthCheck[], extensions: HealthExtension[] = []): HealthChecksResponse => ({
  checks,
  extensions,
  checkedAt: new Date().toISOString(),
});

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
  vi.mocked(getHealthChecks).mockReset();
  vi.mocked(fixHealthCheck).mockReset();
  vi.mocked(toast.success).mockClear();
  vi.mocked(toast.error).mockClear();
  vi.mocked(toast.info).mockClear();
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

const tick = (ms = 0) => act(() => new Promise((resolve) => setTimeout(resolve, ms)));

async function render(checks: HealthCheck[], extensions: HealthExtension[] = []) {
  vi.mocked(getHealthChecks).mockResolvedValue(scan(checks, extensions));
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  await act(async () =>
    root.render(
      <QueryClientProvider client={client}>
        <HealthPanel />
      </QueryClientProvider>,
    ),
  );
  await tick();
}

const titles = () => [...container.querySelectorAll('.font-semibold.text-sm')].map((node) => node.textContent);
const summary = () => container.querySelector('[data-slot="card-description"]')?.textContent;
const extensionsBlock = () => container.querySelector('section[aria-label="Extensions"]');
const button = (name: string) =>
  [...container.querySelectorAll('button')].find((b) => b.textContent?.trim() === name) as
    HTMLButtonElement | undefined;
const spinning = (b: HTMLButtonElement | undefined) =>
  b?.querySelector('[data-slot="loading-indicator"]')?.getAttribute('data-loading') === 'true';

function deferredFix() {
  let resolve!: (response: HealthFixResponse) => void;
  vi.mocked(fixHealthCheck).mockReturnValueOnce(new Promise((r) => (resolve = r)));
  return (response: HealthFixResponse) => act(async () => resolve(response));
}

describe('HealthPanel', () => {
  it('words each row, with plurals', async () => {
    await render([queued(1), running(1), cleanup, late(1)]);

    expect(titles()).toEqual([
      '1 run is stuck before it starts',
      '1 run stopped in the middle of a step',
      'The daily cleanup isn’t running on time',
      '1 background job is late',
    ]);
    expect(container.textContent).toContain(
      'It’s missing or more than an hour late, so old runs and carts aren’t deleted on time.',
    );
    expect(container.textContent).toContain('Fix: schedule it again.');
    expect(container.textContent).toContain(
      'They were due more than 15 minutes ago. WP-Cron only runs when someone visits the site, so a quiet store runs jobs late.',
    );
    expect(button('Queue them again')).toBeDefined();
    expect(button('Queue recovery again')).toBeDefined();
    expect(button('Schedule again')).toBeDefined();
  });

  it('uses the plural and "N+" when the scan was capped', async () => {
    await render([queued(3), running(2), late(12)]);
    expect(titles()).toEqual([
      '3 runs are stuck before they start',
      '2 runs stopped in the middle of a step',
      '12 background jobs are late',
    ]);

    act(() => root.unmount());
    root = createRoot(container);
    await render([queued(120, true)]);
    expect(titles()).toEqual(['120+ runs are stuck before they start']);

    act(() => root.unmount());
    root = createRoot(container);
    await render([queued(1, true)]);
    expect(titles()).toEqual(['1+ runs are stuck before they start']);
    expect(container.textContent).toContain('Fix: queue them again. Each run continues from where it stopped.');
  });

  it('words help and fix lines for one run and for more', async () => {
    await render([queued(1), running(1)]);
    expect(container.textContent).toContain(
      'It is queued or waiting, but no background job will pick it up. This happens when queueing failed.',
    );
    expect(container.textContent).toContain('Fix: queue it again. It continues from where it stopped.');
    expect(container.textContent).toContain(
      'It is still marked running, but its recovery job died. Runs like this never resume on their own.',
    );
    expect(container.textContent).toContain(
      'Fix: queue recovery again. The interrupted step fails; it never runs twice.',
    );

    act(() => root.unmount());
    root = createRoot(container);
    await render([queued(2), running(2)]);
    expect(container.textContent).toContain(
      'They are queued or waiting, but no background job will pick them up. This happens when queueing failed.',
    );
    expect(container.textContent).toContain('Fix: queue them again. Each run continues from where it stopped.');
    expect(container.textContent).toContain(
      'They are still marked running, but their recovery jobs died. Runs like this never resume on their own.',
    );
    expect(container.textContent).toContain('Fix: queue recovery again. The interrupted steps fail; none runs twice.');
    expect(button('Queue them again')).toBeDefined();
    expect(button('Queue recovery again')).toBeDefined();
  });

  it('shows problems first, then the late jobs, and hides ok checks', async () => {
    await render([late(4), cleanup, ok('runs_running'), queued(2)]);
    expect(titles()).toEqual([
      '2 runs are stuck before they start',
      'The daily cleanup isn’t running on time',
      '4 background jobs are late',
    ]);
  });

  it('sums up problems, info only, and all ok', async () => {
    await render([queued(2), cleanup, late(3)]);
    expect(summary()).toMatch(/^2 problems · checked /);

    act(() => root.unmount());
    root = createRoot(container);
    await render([ok('runs_queued'), late(3)]);
    expect(summary()).toMatch(/^No problems · checked /);

    act(() => root.unmount());
    root = createRoot(container);
    await render(allOk);
    expect(summary()).toMatch(/^Checked /);
    expect(container.textContent).toContain('No problems found');
    expect(container.textContent).toContain(
      'Runs are being queued and picked up as they should, and the daily cleanup is scheduled.',
    );
  });

  it('gives the late jobs a guide link and no fix button', async () => {
    await render([late(5)]);
    const link = container.querySelector('a');
    expect(link?.textContent).toBe('How to run WP-Cron from the server');
    expect(link?.getAttribute('href')).toBe(GUIDE);
    expect(link?.getAttribute('target')).toBe('_blank');
    expect(link?.getAttribute('rel')).toBe('noopener');
    expect([...container.querySelectorAll('button')].map((b) => b.textContent?.trim())).toEqual(['Check again']);
  });

  it('spins only the fixing row and shows the fix response’s checks', async () => {
    await render([queued(3), cleanup]);
    const finish = deferredFix();

    await act(async () => button('Queue them again')!.click());
    // react-query tells React about the pending state on a timer.
    await tick();
    expect(fixHealthCheck).toHaveBeenCalledWith('runs_queued');
    expect(spinning(button('Queue them again'))).toBe(true);
    expect(spinning(button('Schedule again'))).toBe(false);

    await finish({ fixed: 3, checks: [ok('runs_queued'), cleanup] });
    await tick();
    expect(titles()).toEqual(['The daily cleanup isn’t running on time']);
    expect(summary()).toMatch(/^1 problem · checked /);
    expect(toast.success).toHaveBeenCalledWith('3 runs queued again.');
    expect(getHealthChecks).toHaveBeenCalledTimes(1);
  });

  it('keeps a fix’s checks over a scan that started before it', async () => {
    await render([queued(3)]);
    let finishScan!: (response: HealthChecksResponse) => void;
    vi.mocked(getHealthChecks).mockReturnValueOnce(new Promise((r) => (finishScan = r)));
    await act(async () => button('Check again')!.click());
    const finishFix = deferredFix();
    await act(async () => button('Queue them again')!.click());

    await finishFix({ fixed: 3, checks: allOk });
    await act(async () => finishScan(scan([queued(3)])));
    await tick();

    expect(container.textContent).toContain('No problems found');
  });

  it('toasts each fix', async () => {
    await render([running(1), cleanup, queued(2)]);

    vi.mocked(fixHealthCheck).mockResolvedValueOnce({ fixed: 1, checks: [cleanup, queued(2)] });
    await act(async () => button('Queue recovery again')!.click());
    expect(toast.success).toHaveBeenLastCalledWith('1 run queued for recovery.');

    vi.mocked(fixHealthCheck).mockResolvedValueOnce({ fixed: 1, checks: [queued(2)] });
    await act(async () => button('Schedule again')!.click());
    expect(toast.success).toHaveBeenLastCalledWith('The daily cleanup is scheduled again.');

    vi.mocked(fixHealthCheck).mockResolvedValueOnce({ fixed: 0, checks: allOk });
    await act(async () => button('Queue them again')!.click());
    expect(toast.info).toHaveBeenCalledWith('Nothing to fix any more.');
    await tick();
    expect(container.textContent).toContain('No problems found');
  });

  it('words the inactive sweep row and toasts its fix', async () => {
    await render([late(1), sweep]);

    expect(titles()).toEqual(['The daily inactive-customer check isn’t running on time', '1 background job is late']);
    expect(container.textContent).toContain(
      'It’s missing or more than a day late, so customers may start their win-back late or miss it.',
    );

    vi.mocked(fixHealthCheck).mockResolvedValueOnce({ fixed: 1, checks: [] });
    await act(async () => button('Schedule again')!.click());
    expect(fixHealthCheck).toHaveBeenLastCalledWith('inactive_sweep_job');
    expect(toast.success).toHaveBeenLastCalledWith('The daily inactive-customer check is scheduled again.');
  });

  it('toasts a failed fix and keeps the row', async () => {
    await render([cleanup]);
    vi.mocked(fixHealthCheck).mockRejectedValueOnce(new Error('The server didn’t answer.'));

    await act(async () => button('Schedule again')!.click());
    await tick();

    expect(toast.error).toHaveBeenCalledWith('Couldn’t fix it. The server didn’t answer.');
    expect(spinning(button('Schedule again'))).toBe(false);
  });

  it('scans again only on Check again, not on window focus', async () => {
    await render([cleanup]);
    expect(getHealthChecks).toHaveBeenCalledTimes(1);

    act(() => {
      focusManager.setFocused(false);
      focusManager.setFocused(true);
    });
    await tick();
    expect(getHealthChecks).toHaveBeenCalledTimes(1);

    await act(async () => button('Check again')!.click());
    await tick(MIN_CHECK_MS);
    expect(getHealthChecks).toHaveBeenCalledTimes(2);
    focusManager.setFocused(undefined);
  });

  it('keeps Check again busy for a moment even when the scan answers at once, then says what it found', async () => {
    await render(allOk);

    await act(async () => button('Check again')!.click());
    await tick(50);
    expect(getHealthChecks).toHaveBeenCalledTimes(2);
    expect(spinning(button('Check again')), 'still busy after the fast answer').toBe(true);
    expect(toast.success).not.toHaveBeenCalled();

    await tick(MIN_CHECK_MS);
    expect(spinning(button('Check again'))).toBe(false);
    expect(toast.success).toHaveBeenCalledWith('No problems found.');
    expect(summary()).toMatch(/^Checked /);
  });

  it('counts the problems the scan found in its toast; the late-jobs row alone is not a problem', async () => {
    await render([queued(3), cleanup, late(4)]);
    await act(async () => button('Check again')!.click());
    await tick(MIN_CHECK_MS + 50);
    expect(toast.info).toHaveBeenCalledWith('Found 2 problems.');

    vi.mocked(getHealthChecks).mockResolvedValue(scan([late(4)]));
    await act(async () => button('Check again')!.click());
    await tick(MIN_CHECK_MS + 50);
    expect(toast.success).toHaveBeenCalledWith('No problems found.');
  });

  it('shows a load error with Try again', async () => {
    vi.mocked(getHealthChecks).mockRejectedValue(new Error('The server didn’t answer.'));
    const client = new QueryClient();
    await act(async () =>
      root.render(
        <QueryClientProvider client={client}>
          <HealthPanel />
        </QueryClientProvider>,
      ),
    );
    // The query retries once, a second later.
    await tick(1100);

    expect(container.querySelector('[role="alert"]')?.textContent).toBe(
      'Couldn’t run the health check. The server didn’t answer.Try again',
    );
    expect(getHealthChecks).toHaveBeenCalledTimes(2);

    vi.mocked(getHealthChecks).mockResolvedValue(scan([cleanup]));
    await act(async () => button('Try again')!.click());
    await tick();
    expect(container.querySelector('[role="alert"]')).toBeNull();
    expect(titles()).toEqual(['The daily cleanup isn’t running on time']);
  });

  it('says no other plugin adds to YayMatik when no module is listed', async () => {
    await render(allOk);

    expect(extensionsBlock()?.textContent).toBe('ExtensionsNo other plugin adds to YayMatik.');
  });

  it('lists each module with no problems or its errors', async () => {
    const time = new Date().toISOString();
    await render(allOk, [
      { id: 'acme_rewards', label: 'Acme Rewards', errors: [] },
      {
        id: 'acme_broken',
        label: 'Acme Broken',
        errors: [{ callback: 'listen', message: 'Boom.', count: 3, time }],
      },
    ]);

    const rows = [...extensionsBlock()!.querySelectorAll('[data-extension]')];
    expect(rows.map((row) => row.getAttribute('data-extension'))).toEqual(['acme_rewards', 'acme_broken']);
    expect(rows[0].textContent).toBe('Acme RewardsNo problems');
    expect(rows[1].textContent).toMatch(/^Acme BrokenBoom\. \(listen, at least 3 times, last .+\)$/);
  });

  it('keeps the modules after a fix, whose response has none', async () => {
    await render([cleanup], [{ id: 'acme_rewards', label: 'Acme Rewards', errors: [] }]);
    const finish = deferredFix();

    await act(async () => button('Schedule again')!.click());
    await finish({ fixed: 1, checks: allOk });
    await tick();

    expect(container.querySelector('[data-extension="acme_rewards"]')).not.toBeNull();
  });
});
