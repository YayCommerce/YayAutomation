import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { EmailFrame } from './PreviewDialog';

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

/** The loaded email as the frame sees it: a whole-number `scrollHeight`, an exact layout height. */
function fakeEmail(iframe: HTMLIFrameElement, size: { scroll: number; exact: number }) {
  const doc = document.implementation.createHTMLDocument('email');
  Object.defineProperty(doc.documentElement, 'scrollHeight', { get: () => size.scroll });
  doc.documentElement.getBoundingClientRect = () => ({ height: size.exact }) as DOMRect;
  Object.defineProperty(iframe, 'contentDocument', { get: () => doc });
}

const render = (width: number | '100%', minWidth?: number) =>
  act(() => root.render(<EmailFrame html="<p>Hi</p>" width={width} minWidth={minWidth} />));

describe('EmailFrame', () => {
  it('never scrolls itself: as tall as the email, rounded up from its exact height', () => {
    render('100%', 640);
    const iframe = container.querySelector('iframe')!;
    const size = { scroll: 337, exact: 337.67 };
    fakeEmail(iframe, size);

    act(() => void iframe.dispatchEvent(new Event('load')));
    expect(iframe.getAttribute('scrolling')).toBe('no');
    expect(iframe.style.height).toBe('338px');
  });

  it('measures nothing before the email has loaded', () => {
    render('100%', 640);
    const iframe = container.querySelector('iframe')!;
    fakeEmail(iframe, { scroll: 900, exact: 900 });

    render(375);
    expect(iframe.style.height).toBe('');
    expect(iframe.className).toContain('h-[60vh]');
  });

  it('measures again on Desktop / Mobile, shrinking too', () => {
    render('100%', 640);
    const iframe = container.querySelector('iframe')!;
    const size = { scroll: 1975, exact: 1975 };
    fakeEmail(iframe, size);
    act(() => void iframe.dispatchEvent(new Event('load')));
    expect(iframe.style.height).toBe('1975px');

    Object.assign(size, { scroll: 1607, exact: 1606.42 });
    render(375);
    expect(iframe.style.height).toBe('1607px');
  });
});
