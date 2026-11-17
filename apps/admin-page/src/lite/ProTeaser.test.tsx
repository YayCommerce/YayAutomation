import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { expect, it, vi } from 'vitest';

import { StepPicker } from '@/routes/workflows/pages/editor/StepPicker';
import { UPGRADE_URL } from './ProTeaser';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

it('ends the trigger and action pickers with one PRO group that opens the upgrade page, gone inside a plugin', () => {
  const container = document.createElement('div');
  const root = createRoot(container);
  for (const kind of ['trigger', 'action'] as const) {
    act(() => root.render(<StepPicker key={kind} kind={kind} onPick={vi.fn()} />));
    const link = container.querySelector<HTMLAnchorElement>('a[href]')!;
    expect(container.querySelector('section:last-child h3')?.textContent).toBe('PRO');
    expect([link.textContent?.startsWith('Get YayMatik Pro'), link.href, link.target]).toEqual([
      true,
      UPGRADE_URL,
      '_blank',
    ]);
    expect(container.querySelectorAll('[disabled]')).toHaveLength(0);
  }
  const woo = [...container.querySelectorAll('button')].find((button) => button.textContent?.includes('WooCommerce'))!;
  act(() => woo.click());
  expect(container.querySelector('a[href]')).toBeNull();
  act(() => root.unmount());
});
