import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { MemoryRouter } from 'react-router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { CartTriggerNotes } from './CartTriggerNotes';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const { wc } = window.yayMatikMeta;
const before = { ...wc };
let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  container = document.createElement('div');
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  Object.assign(wc, before);
});

const mount = (trigger: 'cart' | 'checkout') =>
  act(() =>
    root.render(
      <MemoryRouter>
        <CartTriggerNotes trigger={trigger} />
      </MemoryRouter>,
    ),
  );

describe('CartTriggerNotes', () => {
  it('reads the minutes from the meta, so a saved change shows without a reload', () => {
    wc.abandonMinutes = 45;
    mount('cart');
    expect(container.textContent).toContain('A cart counts as abandoned after 45 minutes without activity.');
  });

  it('warns with a link to Settings only while tracking is off', () => {
    wc.cartTracking = false;
    mount('cart');
    const warning = container.querySelector('[data-slot="alert"]');
    expect(warning?.textContent).toContain('Cart tracking is off, so this trigger never starts.');
    expect(warning?.querySelector('a')?.getAttribute('href')).toBe('/settings');

    wc.cartTracking = true;
    mount('cart');
    expect(container.querySelector('[data-slot="alert"]')).toBeNull();
  });

  it('leaves WooCommerce being off to "Install WooCommerce"', () => {
    wc.cartTracking = false;
    wc.active = false;
    mount('cart');
    expect(container.querySelector('[data-slot="alert"]')).toBeNull();
  });

  it('says each trigger never starts the other, and the rest in both', () => {
    mount('cart');
    expect(container.textContent).toContain('Carts that reached checkout start Checkout abandoned instead');
    expect(container.textContent).not.toContain('They never start Cart abandoned.');

    mount('checkout');
    expect(container.textContent).toContain('Only carts that reached checkout with an email.');
    expect(container.textContent).toContain('Runs stop as soon as the shopper places an order.');
    expect(container.textContent).toContain(
      'Shoppers who unsubscribed, or already got a reminder this week for another cart, are skipped.',
    );
  });
});
