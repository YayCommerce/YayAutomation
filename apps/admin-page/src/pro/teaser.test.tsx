import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { expect, it, vi } from 'vitest';

import { StepPicker } from '@/routes/workflows/pages/editor/StepPicker';
import { ProTeaser } from './teaser';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

it('has no teaser', () => {
  const container = document.createElement('div');
  const root = createRoot(container);
  act(() => root.render(<StepPicker kind="trigger" onPick={vi.fn()} />));

  expect(ProTeaser).toBeNull();
  expect(container.textContent).not.toContain('Get YayMatik Pro');
  act(() => root.unmount());
});
