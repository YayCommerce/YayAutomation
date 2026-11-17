import { act } from 'react';
import type { EmailPreview, EmailTestResult } from '@yaymatik/types';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { toast } from '@/components/ui/sonner';
import { EmailToolsContext, type EmailTools } from '../../core/fields/emailTools';
import { EmailActions } from './EmailActions';
import { sampleProductsText } from './PreviewDialog';

vi.mock('@/components/ui/sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

/** Server HTML: an escaped `&` must reach the iframe as it is. */
const html = '<!DOCTYPE html><html><body><p>Tom &amp; Jerry</p></body></html>';

const preview = (changes: Partial<EmailPreview> = {}): EmailPreview => ({
  subject: 'Tom & Jerry',
  heading: 'Tom & Jerry',
  html,
  to: 'jane@example.test',
  sample: false,
  order: '1745',
  customer: null,
  sampleProducts: [],
  ...changes,
});

const sent: EmailTestResult = {
  sentTo: 'you@example.test',
  subject: '[Test] Tom & Jerry',
  sample: false,
  sampleProducts: [],
};

/** Rejects like `ApiError` for a step with issues. */
const refused = () =>
  Object.assign(new Error('Send email needs a subject.'), {
    issues: [{ code: 'invalid_config', message: 'Send email needs a subject.', nodeId: 'm', field: 'subject' }],
  });

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
  vi.mocked(toast.success).mockClear();
  vi.mocked(toast.error).mockClear();
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

function renderActions(tools: Partial<EmailTools>) {
  const value: EmailTools = {
    config: {},
    preview: vi.fn(async () => preview()),
    sendTest: vi.fn(async () => sent),
    ...tools,
  };
  act(() =>
    root.render(
      <EmailToolsContext.Provider value={value}>
        <EmailActions />
      </EmailToolsContext.Provider>,
    ),
  );
  return value;
}

const button = (name: string) =>
  [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === name) as HTMLButtonElement;

async function click(element: HTMLElement) {
  await act(async () => element.click());
}

const dialog = () => document.querySelector('[role="dialog"]');

describe('EmailActions', () => {
  it('says where a test goes', () => {
    renderActions({});
    expect(container.textContent).toContain('Test goes to you@example.test');
  });

  it('opens the preview: mail header, order note, and the server HTML unchanged in a script-free iframe', async () => {
    const tools = renderActions({});
    await click(button('Preview'));

    expect(tools.preview).toHaveBeenCalledTimes(1);
    const open = dialog()!;
    expect(open.textContent).toContain('Email preview');
    expect(open.textContent).toContain('Order #1745 data · nothing is sent');
    expect(open.textContent).toContain('Tom & Jerry');
    expect(open.textContent).toContain('to jane@example.test');
    expect(open.textContent).toContain('Example Store');
    expect(open.textContent).toContain('<store@example.test>');
    expect(open.textContent).not.toContain('Sample data');

    const iframe = open.querySelector('iframe')!;
    expect(iframe.getAttribute('srcdoc')).toBe(html);
    expect(iframe.getAttribute('sandbox')).toBe('allow-same-origin');
    // Wider than 600px, as in a mail client: WooCommerce's `max-width: 600px` rules shrink the text.
    expect(iframe.style.width).toBe('100%');
    expect(iframe.style.minWidth).toBe('640px');
  });

  it('draws the chrome: 32px from the edges, sender avatar, Desktop chosen, the dialog focused', async () => {
    renderActions({});
    await click(button('Preview'));
    const open = dialog() as HTMLElement;

    expect(open.className).toContain('inset-8');
    expect(open.className).not.toContain('top-[50%]');
    expect(open.querySelector('.bg-avatar-soft')?.textContent).toBe('E');
    expect(button('Desktop').getAttribute('aria-pressed')).toBe('true');
    expect(button('Mobile').getAttribute('aria-pressed')).toBe('false');
    // Not a control: none shows a focus ring until the keyboard moves focus.
    expect(document.activeElement).toBe(open);
  });

  it('shows the Sample data badge for made-up values', async () => {
    renderActions({ preview: vi.fn(async () => preview({ sample: true, order: null })) });
    await click(button('Preview'));

    expect(dialog()!.textContent).toContain('Sample data');
    expect(dialog()!.textContent).not.toContain('Order #');
  });

  it('names the customer account a customer-only trigger previews with', async () => {
    renderActions({ preview: vi.fn(async () => preview({ order: null, customer: 'Ann Lee' })) });
    await click(button('Preview'));

    expect(dialog()!.textContent).toContain('Customer Ann Lee data · nothing is sent');
  });

  it('says when a grid shows sample products because no recent order has any', async () => {
    renderActions({ preview: vi.fn(async () => preview({ sampleProducts: ['cross_sells'] })) });
    await click(button('Preview'));

    expect(dialog()!.textContent).toContain('Order #1745');
    expect(dialog()!.textContent).toContain('Sample products: no recent order has cross-sells');
  });

  it('names both grids when neither has a recent order', () => {
    expect(sampleProductsText(['cross_sells', 'upsells'])).toBe(
      'Sample products: no recent order has cross-sells or upsells',
    );
  });

  it('says the same in the Send test toast', async () => {
    renderActions({ sendTest: vi.fn(async () => ({ ...sent, sampleProducts: ['upsells' as const] })) });
    await click(button('Preview'));
    await click(button('Send test'));

    expect(toast.success).toHaveBeenCalledWith('Test email sent to you@example.test', {
      description: 'Sample products: no recent order has upsells',
    });
  });

  it('narrows the email to 375px on Mobile', async () => {
    renderActions({});
    await click(button('Preview'));
    await click(button('Mobile'));

    expect(dialog()!.querySelector('iframe')!.style.width).toBe('375px');
    expect(dialog()!.querySelector('iframe')!.style.minWidth).toBe('');
    expect(button('Mobile').getAttribute('aria-pressed')).toBe('true');
  });

  it('lists the issues instead of opening an empty dialog', async () => {
    renderActions({ preview: vi.fn(async () => Promise.reject(refused())) });
    await click(button('Preview'));

    expect(dialog()).toBeNull();
    expect(container.textContent).toContain('Fix this step first');
    expect(container.textContent).toContain('Send email needs a subject.');
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('clears the issues after an edit', async () => {
    const tools = renderActions({ preview: vi.fn(async () => Promise.reject(refused())) });
    await click(button('Preview'));
    renderActions({ ...tools, config: { subject: 'Hi' } });

    expect(container.textContent).not.toContain('Fix this step first');
  });

  it('sends a test from the preview and says where it went', async () => {
    const tools = renderActions({});
    await click(button('Preview'));
    await click(button('Send test'));

    expect(tools.sendTest).toHaveBeenCalledTimes(1);
    expect(toast.success).toHaveBeenCalledWith('Test email sent to you@example.test', {});
  });

  it("shows the server's error when the test fails", async () => {
    renderActions({ sendTest: vi.fn(async () => Promise.reject(new Error('Couldn’t send the test email.'))) });
    await click(button('Preview'));
    await click(button('Send test'));

    expect(toast.error).toHaveBeenCalledWith('Couldn’t send the test email.');
  });
});
