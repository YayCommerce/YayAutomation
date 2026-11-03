import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { MemoryRouter, Route, Routes } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';

import TemplatePreviewPage from './TemplatePreviewPage';

vi.mock('../../shared/canvas/ReadOnlyCanvas', () => ({ ReadOnlyCanvas: () => null }));
vi.mock('./useCreateDraft', () => ({
  installFirst: () => null,
  useCreateDraft: () => ({ createDraft: () => {}, isCreating: false }),
}));

// Tells React this is a test, so `act()` flushes updates without warnings.
(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

describe('TemplatePreviewPage, Before you start', () => {
  const { wc } = window.yayMatikMeta;
  const before = { ...wc };
  let root: Root;
  let container: HTMLElement;

  const show = (id: string) => {
    container = document.body.appendChild(document.createElement('div'));
    root = createRoot(container);
    act(() =>
      root.render(
        <MemoryRouter initialEntries={[`/workflows/templates/${id}`]}>
          <Routes>
            <Route path="/workflows/templates/:templateId" element={<TemplatePreviewPage />} />
          </Routes>
        </MemoryRouter>,
      ),
    );
  };

  afterEach(() => {
    act(() => root.unmount());
    container.remove();
    Object.assign(wc, before);
  });

  it('asks to turn on product reviews, with a link to WooCommerce settings in a new tab', () => {
    wc.reviewsEnabled = false;
    show('review-request');
    expect(container.textContent).toContain('Before you start');
    expect(container.textContent).toContain('Turn on product reviews. Without them there’s nothing to review.');
    const link = [...container.querySelectorAll('a')].find((a) => a.textContent === 'Open WooCommerce settings')!;
    expect(link.getAttribute('href')).toBe('https://example.test/wp-admin/admin.php?page=wc-settings&tab=products');
    expect(link.getAttribute('target')).toBe('_blank');
  });

  it('says nothing while reviews are on', () => {
    wc.reviewsEnabled = true;
    show('review-request');
    expect(container.textContent).not.toContain('Before you start');
  });

  it('says nothing on a template that reads no reviews', () => {
    wc.reviewsEnabled = false;
    show('welcome-email');
    expect(container.textContent).not.toContain('Turn on product reviews');
  });
});
