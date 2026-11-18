import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { MemoryRouter } from 'react-router';
import { expect, it, vi } from 'vitest';

import { StepPicker } from '@/routes/workflows/pages/editor/StepPicker';
import { TemplatesDialog } from '@/routes/workflows/pages/list/library/TemplatesDialog';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const noTeaser = (text: string | null | undefined) => {
  expect(text).not.toContain('PRO');
  expect(text).not.toContain('Get YayMatik Pro');
};

it('shows the 8 templates in the library and no PRO group', () => {
  const root = createRoot(document.createElement('div'));
  act(() =>
    root.render(
      <MemoryRouter>
        <TemplatesDialog open onOpenChange={vi.fn()} />
      </MemoryRouter>,
    ),
  );
  const dialog = document.querySelector('[role="dialog"]')!;
  expect(dialog.querySelectorAll('a[href^="/workflows/templates/"]')).toHaveLength(8);
  noTeaser(dialog.textContent);
  act(() => root.unmount());
});

it('lists 9 triggers and 6 actions in the pickers and no PRO group', () => {
  const container = document.createElement('div');
  const root = createRoot(container);
  const counts = (['trigger', 'action'] as const).map((kind) => {
    act(() => root.render(<StepPicker key={kind} kind={kind} onPick={vi.fn()} />));
    noTeaser(container.textContent);
    // Each plugin row's badge counts its steps.
    return [...container.querySelectorAll('[data-slot="badge"]')].reduce(
      (sum, badge) => sum + Number(badge.textContent),
      0,
    );
  });
  expect(counts).toEqual([9, 6]);
  act(() => root.unmount());
});
