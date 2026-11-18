import { act } from 'react';
import meta from '@test-meta';
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

  it("heads another plugin's steps with the category labels PHP sends", async () => {
    vi.resetModules();
    const automation = meta.automation;
    window.yayMatikMeta = {
      ...meta,
      automation: {
        ...automation,
        categories: { ...automation.categories, acme_rewards: 'Rewards' },
        plugins: [...automation.plugins, { id: 'acme', label: 'Acme', description: '', active: true, logo: '' }],
        steps: {
          ...automation.steps,
          acme_add_points: {
            kind: 'action',
            plugin: 'acme',
            category: 'acme_rewards',
            requires: [],
            label: 'Add reward points',
            description: 'Gives the customer reward points.',
            icon: '',
            summary: '',
            onError: 'stop',
            fields: [],
            outputs: [],
          },
        },
      },
    } as unknown as Window['yayMatikMeta'];
    try {
      const { StepPicker: Picker } = await import('./StepPicker');
      act(() => root.render(<Picker kind="action" onPick={vi.fn()} />));
      const acme = [...container.querySelectorAll('button')].find((button) => button.textContent?.includes('Acme'))!;
      act(() => acme.click());

      expect([...container.querySelectorAll('h3')].map((heading) => heading.textContent)).toEqual(['Rewards']);
      expect(container.textContent).toContain('Add reward points');
    } finally {
      window.yayMatikMeta = meta as unknown as Window['yayMatikMeta'];
    }
  });
});
