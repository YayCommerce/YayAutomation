import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { MemoryRouter } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { EditorLayout } from './EditorLayout';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
});

afterEach(() => container.remove());

describe('EditorLayout', () => {
  it('stops the page from scrolling while it is open', () => {
    const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    act(() =>
      root.render(
        <MemoryRouter>
          <EditorLayout title="W1">canvas</EditorLayout>
        </MemoryRouter>,
      ),
    );
    expect(document.documentElement.classList.contains('yaymatik-editor-open')).toBe(true);
    expect(scrollTo).toHaveBeenCalledWith(0, 0);

    act(() => root.unmount());
    expect(document.documentElement.classList.contains('yaymatik-editor-open')).toBe(false);
    scrollTo.mockRestore();
  });
});
