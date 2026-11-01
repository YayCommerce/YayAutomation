import { act } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { SettingsResponse } from '@yaymatik/types';
import { createRoot, type Root } from 'react-dom/client';
import { createHashRouter, RouterProvider } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { toast } from '@/components/ui/sonner';
import { getSettings, saveSettings, SettingsError } from './api/settings.api';
import SettingsPage from './SettingsPage';

vi.mock('@/components/ui/sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('./api/health.api', () => ({
  getHealthChecks: vi.fn(async () => ({ checks: [], checkedAt: new Date().toISOString() })),
  fixHealthCheck: vi.fn(),
}));
vi.mock('./api/settings.api', async (original) => ({
  ...(await original<typeof import('./api/settings.api')>()),
  getSettings: vi.fn(),
  saveSettings: vi.fn(),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const settings: SettingsResponse = {
  cartTracking: true,
  abandonMinutes: 60,
  captureGuestEmail: true,
  cartRetentionDays: 60,
  runRetentionDays: 90,
  unsubscribeText: '',
  unsubscribeDefault: 'You won’t get emails like this one from Shop any more.',
};

let container: HTMLDivElement;
let headerSlot: HTMLDivElement;
let root: Root;
let router: ReturnType<typeof createHashRouter>;

beforeEach(() => {
  // Layout's header slot, where the page puts Save changes.
  headerSlot = document.createElement('div');
  headerSlot.id = 'yay-header-actions';
  container = document.createElement('div');
  document.body.append(headerSlot, container);
  root = createRoot(container);
  vi.mocked(getSettings).mockResolvedValue(settings);
  vi.mocked(saveSettings).mockReset();
  vi.mocked(toast.success).mockClear();
});

afterEach(() => {
  act(() => root.unmount());
  router.dispose();
  container.remove();
  headerSlot.remove();
});

async function render() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  router = createHashRouter([
    { path: '/settings', element: <SettingsPage /> },
    { path: '/workflows', element: <p id="workflows">Workflows</p> },
  ]);
  await act(() => router.navigate('/settings'));
  await act(async () =>
    root.render(
      <QueryClientProvider client={client}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    ),
  );
  // The query resolves a few ticks after the first commit.
  await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
}

const field = <T extends HTMLElement>(name: string) => document.getElementById(`yaymatik-setting-${name}`) as T;
const errorOf = (name: string) => document.getElementById(`yaymatik-setting-${name}-error`)?.textContent;
const button = (name: string, scope: ParentNode = document) =>
  [...scope.querySelectorAll('button')].find((b) => b.textContent?.trim() === name) as HTMLButtonElement | undefined;
const saveButton = () => headerSlot.querySelector('button') as HTMLButtonElement;
const saveAlert = () => container.querySelector('[data-slot="alert"][role="alert"]');
const dialog = () => document.querySelector('[role="alertdialog"]');
const sentBody = (call: number) => vi.mocked(saveSettings).mock.calls[call][0];

/** Types like a user: React listens to `input`, and reads the value through the native setter. */
function type(element: HTMLInputElement | HTMLTextAreaElement, value: string) {
  const prototype = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
  act(() => {
    Object.getOwnPropertyDescriptor(prototype, 'value')!.set!.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
  });
}

async function click(element: HTMLElement) {
  await act(async () => element.click());
}

async function leave() {
  await act(() => router.navigate('/workflows'));
}

/** Whether leaving the page now gets the browser's prompt. */
function promptsOnUnload() {
  const event = new Event('beforeunload', { cancelable: true });
  window.dispatchEvent(event);
  return event.defaultPrevented;
}

function deferredSave() {
  let resolve!: (saved: SettingsResponse) => void;
  vi.mocked(saveSettings).mockReturnValue(new Promise((r) => (resolve = r)));
  return (saved: SettingsResponse) => act(async () => resolve(saved));
}

describe('SettingsPage', () => {
  it('puts Save changes in the header slot', async () => {
    await render();
    expect(saveButton().textContent).toContain('Save changes');
    expect(container.contains(saveButton())).toBe(false);
  });

  it('sends only the changed keys, numbers as numbers', async () => {
    vi.mocked(saveSettings).mockResolvedValue({ ...settings, abandonMinutes: 30, captureGuestEmail: false });
    await render();

    type(field<HTMLInputElement>('abandonMinutes'), '30');
    await click(field<HTMLButtonElement>('captureGuestEmail'));
    await click(saveButton());

    expect(sentBody(0)).toEqual({ abandonMinutes: 30, captureGuestEmail: false });
    expect(toast.success).toHaveBeenCalledWith('Settings saved');
  });

  it('saves with nothing changed', async () => {
    vi.mocked(saveSettings).mockResolvedValue(settings);
    await render();

    await click(saveButton());

    expect(sentBody(0)).toEqual({});
    expect(toast.success).toHaveBeenCalledWith('Settings saved');
  });

  it('shows a spinner while saving, stays enabled and ignores more clicks', async () => {
    const finish = deferredSave();
    await render();

    await click(saveButton());
    expect(saveButton().disabled).toBe(false);
    expect(saveButton().querySelector('[data-slot="loading-indicator"]')?.getAttribute('data-loading')).toBe('true');

    await click(saveButton());
    expect(saveSettings).toHaveBeenCalledTimes(1);

    await finish(settings);
    expect(saveButton().querySelector('[data-slot="loading-indicator"]')?.getAttribute('data-loading')).toBe('false');
  });

  it('shows a 400’s errors under their fields and keeps the edits', async () => {
    vi.mocked(saveSettings).mockRejectedValue(
      new SettingsError('Fix the highlighted settings.', [
        { field: 'abandonMinutes', message: 'Enter a whole number from 15 to 1440.' },
        { field: 'unsubscribeText', message: 'Enter text.' },
      ]),
    );
    await render();

    type(field<HTMLInputElement>('abandonMinutes'), '30');
    type(field<HTMLTextAreaElement>('unsubscribeText'), 'Bye');
    await click(saveButton());

    expect(errorOf('abandonMinutes')).toBe('Enter a whole number from 15 to 1440.');
    expect(errorOf('unsubscribeText')).toBe('Enter text.');
    expect(errorOf('runRetentionDays')).toBeUndefined();
    expect(saveAlert()?.textContent).toBe('Couldn’t save your settings. Fix the highlighted settings.');
    expect(button('Try again')).toBeUndefined();
    expect(field<HTMLInputElement>('abandonMinutes').value).toBe('30');
    expect(field<HTMLTextAreaElement>('unsubscribeText').value).toBe('Bye');

    type(field<HTMLInputElement>('abandonMinutes'), '31');
    expect(errorOf('abandonMinutes')).toBeUndefined();
    expect(errorOf('unsubscribeText')).toBe('Enter text.');
    expect(saveAlert()).not.toBeNull();

    type(field<HTMLTextAreaElement>('unsubscribeText'), 'Bye now');
    expect(saveAlert()).toBeNull();
  });

  it('keeps edits made while a save is running', async () => {
    const finish = deferredSave();
    await render();

    type(field<HTMLInputElement>('abandonMinutes'), '30');
    await click(saveButton());
    type(field<HTMLTextAreaElement>('unsubscribeText'), 'Bye');
    await finish({ ...settings, abandonMinutes: 30 });

    expect(field<HTMLInputElement>('abandonMinutes').value).toBe('30');
    expect(field<HTMLTextAreaElement>('unsubscribeText').value).toBe('Bye');
    expect(promptsOnUnload()).toBe(true);
  });

  it('offers Try again after a network error, which sends the edits again', async () => {
    vi.mocked(saveSettings).mockRejectedValueOnce(new SettingsError('The server didn’t answer.'));
    vi.mocked(saveSettings).mockResolvedValueOnce({ ...settings, runRetentionDays: 30 });
    await render();

    type(field<HTMLInputElement>('runRetentionDays'), '30');
    await click(saveButton());
    expect(saveAlert()?.textContent).toContain('Couldn’t save your settings. The server didn’t answer.');
    expect(field<HTMLInputElement>('runRetentionDays').value).toBe('30');

    await click(button('Try again')!);
    expect(saveSettings).toHaveBeenCalledTimes(2);
    expect(sentBody(1)).toEqual({ runRetentionDays: 30 });
    expect(saveAlert()).toBeNull();
  });

  it('doesn’t send a value out of range', async () => {
    await render();

    type(field<HTMLInputElement>('abandonMinutes'), '5');
    await click(saveButton());

    expect(saveSettings).not.toHaveBeenCalled();
    expect(errorOf('abandonMinutes')).toBe('Enter a whole number from 15 to 1440.');
  });

  it('hides the tracking fields while tracking is off', async () => {
    vi.mocked(getSettings).mockResolvedValue({ ...settings, cartTracking: false });
    await render();
    expect(field('abandonMinutes')).toBeNull();
    expect(field('captureGuestEmail')).toBeNull();
    expect(field('cartRetentionDays')).not.toBeNull();

    await click(field<HTMLButtonElement>('cartTracking'));
    expect(field('abandonMinutes')).not.toBeNull();
    expect(field('captureGuestEmail')).not.toBeNull();
  });

  it('turning tracking off drops its fields’ edits and errors', async () => {
    vi.mocked(saveSettings).mockResolvedValue({ ...settings, cartTracking: false });
    await render();

    type(field<HTMLInputElement>('abandonMinutes'), '5');
    await click(saveButton());
    expect(errorOf('abandonMinutes')).toBe('Enter a whole number from 15 to 1440.');
    expect(saveSettings).not.toHaveBeenCalled();

    await click(field<HTMLButtonElement>('cartTracking'));
    await click(saveButton());

    expect(sentBody(0)).toEqual({ cartTracking: false });
    expect(toast.success).toHaveBeenCalledWith('Settings saved');

    await click(field<HTMLButtonElement>('cartTracking'));
    expect(field<HTMLInputElement>('abandonMinutes').value).toBe('60');
    expect(errorOf('abandonMinutes')).toBeUndefined();
  });

  describe('leaving with unsaved changes', () => {
    it('leaves without asking when nothing changed, or after a save', async () => {
      vi.mocked(saveSettings).mockResolvedValue({ ...settings, abandonMinutes: 30 });
      await render();
      type(field<HTMLInputElement>('abandonMinutes'), '30');
      await click(saveButton());

      await leave();

      expect(dialog()).toBeNull();
      expect(document.getElementById('workflows')).not.toBeNull();
    });

    it('Discard changes leaves', async () => {
      await render();
      type(field<HTMLInputElement>('abandonMinutes'), '30');

      await leave();
      expect(dialog()?.textContent).toContain('Unsaved changes');
      expect(document.getElementById('workflows')).toBeNull();

      await click(button('Discard changes', dialog()!)!);
      expect(document.getElementById('workflows')).not.toBeNull();
      expect(saveSettings).not.toHaveBeenCalled();
    });

    it('Save changes saves, then leaves', async () => {
      vi.mocked(saveSettings).mockResolvedValue({ ...settings, abandonMinutes: 30 });
      await render();
      type(field<HTMLInputElement>('abandonMinutes'), '30');

      await leave();
      await click(button('Save changes', dialog()!)!);

      expect(sentBody(0)).toEqual({ abandonMinutes: 30 });
      expect(document.getElementById('workflows')).not.toBeNull();
    });

    it('Save changes during a running save waits for it, then leaves', async () => {
      const finish = deferredSave();
      await render();
      type(field<HTMLInputElement>('abandonMinutes'), '30');
      await click(saveButton());

      await leave();
      await click(button('Save changes', dialog()!)!);
      await finish({ ...settings, abandonMinutes: 30 });

      expect(saveSettings).toHaveBeenCalledTimes(1);
      expect(document.getElementById('workflows')).not.toBeNull();
    });

    it('Save changes that fails stays and shows the error', async () => {
      vi.mocked(saveSettings).mockRejectedValue(new SettingsError('The server didn’t answer.'));
      await render();
      type(field<HTMLInputElement>('abandonMinutes'), '30');

      await leave();
      await click(button('Save changes', dialog()!)!);

      expect(dialog()).toBeNull();
      expect(document.getElementById('workflows')).toBeNull();
      expect(saveAlert()?.textContent).toContain('The server didn’t answer.');
      expect(field<HTMLInputElement>('abandonMinutes').value).toBe('30');
    });

    it('Close stays', async () => {
      await render();
      type(field<HTMLInputElement>('abandonMinutes'), '30');

      await leave();
      await click(dialog()!.querySelector('button[aria-label="Close"]') as HTMLButtonElement);

      expect(dialog()).toBeNull();
      expect(document.getElementById('workflows')).toBeNull();
      expect(field<HTMLInputElement>('abandonMinutes').value).toBe('30');
    });

    it('asks the browser to confirm only while there are unsaved changes', async () => {
      vi.mocked(saveSettings).mockResolvedValue({ ...settings, abandonMinutes: 30 });
      await render();
      expect(promptsOnUnload()).toBe(false);

      type(field<HTMLInputElement>('abandonMinutes'), '30');
      expect(promptsOnUnload()).toBe(true);

      await click(saveButton());
      expect(promptsOnUnload()).toBe(false);
    });
  });
});
