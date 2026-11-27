import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { CreateWorkflowDialog } from './CreateWorkflowDialog';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement;
let root: Root;
const onSelectTrigger = vi.fn();
const onBrowseTemplates = vi.fn();

beforeEach(async () => {
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
  await act(async () =>
    root.render(
      <CreateWorkflowDialog
        open
        onOpenChange={() => {}}
        onSelectTrigger={onSelectTrigger}
        onBrowseTemplates={onBrowseTemplates}
        creating={false}
      />,
    ),
  );
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

describe('CreateWorkflowDialog', () => {
  it('offers only the two ways to start: no assistant box, examples or "coming soon"', () => {
    expect(document.querySelector('[aria-disabled]')).toBeNull();
    expect(document.querySelector('ul[aria-label="Example prompts"]')).toBeNull();
    expect(document.body.textContent).not.toMatch(/coming soon|describe it/i);
    expect(document.body.textContent).toContain('What do you want to automate?');
  });

  it('opens templates or the trigger picker from its two cards', () => {
    const cards = [...document.querySelectorAll('button')].filter((b) =>
      /Browse templates|Choose a trigger/.test(b.textContent ?? ''),
    );
    expect(cards).toHaveLength(2);
    act(() => cards[0].click());
    expect(onBrowseTemplates).toHaveBeenCalledOnce();
    act(() => cards[1].click());
    expect(onSelectTrigger).toHaveBeenCalledOnce();
  });
});
