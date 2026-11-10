import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { StepPicker } from './StepPicker';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

describe('StepPicker', () => {
  it('lists WooCommerce triggers under Order, Carts, Customers, then Reviews', () => {
    act(() => root.render(<StepPicker kind="trigger" onPick={vi.fn()} />));
    const woo = [...container.querySelectorAll('button')].find((button) =>
      button.textContent?.includes('WooCommerce'),
    )!;
    act(() => woo.click());

    const headings = [...container.querySelectorAll('h3')].map((heading) => heading.textContent);
    expect(headings).toEqual(['Order', 'Carts', 'Customers', 'Reviews']);
    const reviews = container.querySelectorAll('section')[3];
    expect(reviews.textContent).toContain('Review posted');
    expect(reviews.textContent).toContain('A product review is approved');
  });
});
