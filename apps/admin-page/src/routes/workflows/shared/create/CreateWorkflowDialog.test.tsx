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

const focusable = 'a[href], button, input, textarea, select, [tabindex]:not([tabindex="-1"]), [contenteditable="true"]';
const describeBox = () => document.querySelector('div[aria-disabled]') as HTMLElement;
const examples = () => [...document.querySelectorAll('ul[aria-label="Example prompts"] li')] as HTMLElement[];

describe('CreateWorkflowDialog describe box', () => {
  it('has nothing to focus or type in, and its parts look disabled', () => {
    expect(describeBox()).not.toBeNull();
    expect(describeBox().querySelectorAll(focusable)).toHaveLength(0);
    expect(describeBox().className).toContain('cursor-not-allowed');
    expect(describeBox().querySelectorAll('.opacity-50')).toHaveLength(2);
    expect(describeBox().textContent).toContain('Coming soon');
  });

  it('shows the 4 examples as disabled, not clickable', () => {
    expect(examples()).toHaveLength(4);
    for (const chip of examples()) {
      expect(chip.getAttribute('aria-disabled')).toBe('true');
      expect(chip.querySelectorAll(focusable)).toHaveLength(0);
      act(() => chip.click());
    }
    act(() => describeBox().click());
    expect(onSelectTrigger).not.toHaveBeenCalled();
    expect(onBrowseTemplates).not.toHaveBeenCalled();
  });

  it('keeps the two ways to start on your own usable', () => {
    const cards = [...document.querySelectorAll('button')].filter((b) =>
      /Browse templates|Choose a trigger/.test(b.textContent ?? ''),
    );
    expect(cards).toHaveLength(2);
    act(() => cards[0].click());
    expect(onBrowseTemplates).toHaveBeenCalledOnce();
  });
});
