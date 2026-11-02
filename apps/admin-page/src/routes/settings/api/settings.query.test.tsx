import { act } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { SettingsResponse } from '@yaymatik/types';
import { createRoot } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { workflowKeys } from '@/routes/workflows/api/workflow.query';
import { saveSettings } from './settings.api';
import { useSaveSettings } from './settings.query';

vi.mock('./settings.api', async (original) => ({
  ...(await original<typeof import('./settings.api')>()),
  saveSettings: vi.fn(),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const { wc } = window.yayMatikMeta;
const before = { ...wc };

afterEach(() => {
  Object.assign(wc, before);
});

const saved = (changes: Partial<SettingsResponse>) =>
  ({ cartTracking: false, abandonMinutes: 60, ...changes }) as SettingsResponse;

async function save(response: SettingsResponse) {
  vi.mocked(saveSettings).mockResolvedValue(response);
  const client = new QueryClient();
  client.setQueryData(workflowKeys.detail('7'), { id: '7' });
  let mutate!: ReturnType<typeof useSaveSettings>['mutateAsync'];
  function Harness() {
    mutate = useSaveSettings().mutateAsync;
    return null;
  }
  const root = createRoot(document.createElement('div'));
  await act(async () =>
    root.render(
      <QueryClientProvider client={client}>
        <Harness />
      </QueryClientProvider>,
    ),
  );
  await act(async () => void (await mutate({})));
  act(() => root.unmount());
  return client;
}

describe('useSaveSettings', () => {
  it('writes the saved tracking and minutes into the page meta', async () => {
    await save(saved({ cartTracking: true, abandonMinutes: 30 }));
    expect(window.yayMatikMeta.wc).toMatchObject({ cartTracking: true, abandonMinutes: 30 });
  });

  it('drops cached workflows when tracking changes: their issues are about the old value', async () => {
    const client = await save(saved({ cartTracking: true }));
    expect(client.getQueryData(workflowKeys.detail('7'))).toBeUndefined();
  });

  it('keeps cached workflows when only the minutes change', async () => {
    const client = await save(saved({ abandonMinutes: 30 }));
    expect(client.getQueryData(workflowKeys.detail('7'))).toEqual({ id: '7' });
  });
});
