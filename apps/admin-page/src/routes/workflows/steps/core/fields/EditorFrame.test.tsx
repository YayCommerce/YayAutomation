import { act } from 'react';
import meta from '@test-meta';
import type { StepId } from '@yaymatik/types';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { editorFooter, EditorFrame, embedUrl } from './EditorFrame';
import { EditorToolsContext } from './editorTools';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

describe('EditorFrame', () => {
  let root: Root;
  let container: HTMLElement;
  const onSave = vi.fn();
  const onClose = vi.fn();

  const show = () => {
    container = document.body.appendChild(document.createElement('div'));
    root = createRoot(container);
    act(() =>
      root.render(
        <EditorFrame
          title="Send rewards email"
          opened={{ url: 'about:blank', channel: 'abc', sample: { orderId: 12, userId: 5 } }}
          init={{ value: '7', config: { design_id: '7' } }}
          onSave={onSave}
          onClose={onClose}
        />,
      ),
    );
    return document.querySelector('iframe')!;
  };

  const send = (
    frame: HTMLIFrameElement,
    data: Record<string, unknown>,
    channel = new URL(frame.src).searchParams.get('yaymatik_channel'),
  ) =>
    act(() =>
      window.dispatchEvent(
        new MessageEvent('message', {
          origin: window.location.origin,
          source: frame.contentWindow,
          data: { source: 'yaymatik-editor', version: 1, channel, ...data },
        }),
      ),
    );

  const button = (name: string) =>
    [...document.querySelectorAll('button')].find(
      (b) => b.textContent === name || b.getAttribute('aria-label') === name,
    )!;

  afterEach(() => {
    act(() => root.unmount());
    container.remove();
    vi.clearAllMocks();
  });

  it('opens the plugin’s page with the channel, titled by the step', () => {
    const frame = show();
    expect(frame.getAttribute('src')).toBe('about:blank?yaymatik_embed=1&yaymatik_channel=abc');
    expect(document.body.textContent).toContain('Send rewards email');
    // Before a hash route, where the plugin's page reads its query.
    expect(embedUrl('https://shop.test/wp-admin/admin.php?page=acme#/designs/7', 'x')).toBe(
      'https://shop.test/wp-admin/admin.php?page=acme&yaymatik_embed=1&yaymatik_channel=x#/designs/7',
    );
  });

  it('answers ready with init, to this site’s origin only', () => {
    const frame = show();
    const post = vi.spyOn(frame.contentWindow!, 'postMessage').mockImplementation(() => {});
    send(frame, { type: 'ready' });

    expect(post).toHaveBeenCalledWith(
      { source: 'yaymatik-editor', version: 1, channel: 'abc', type: 'init', value: '7', config: { design_id: '7' } },
      window.location.origin,
    );
  });

  it('hands a save to the step', () => {
    const frame = show();
    send(frame, { type: 'save', value: '12', label: 'Spring' });
    expect(onSave).toHaveBeenCalledWith('12', 'Spring');
  });

  it('shows an error the editor sends in its header', () => {
    const frame = show();
    send(frame, { type: 'error', message: 'Design not found.' });
    expect(document.body.textContent).toContain('Design not found.');
  });

  it('closes at once when nothing changed, and asks first when the editor has changes', () => {
    const frame = show();
    act(() => button('Close').click());
    expect(onClose).toHaveBeenCalledTimes(1);

    send(frame, { type: 'change', value: '7', dirty: true });
    act(() => button('Close').click());
    expect(onClose).toHaveBeenCalledTimes(1);
    expect(document.body.textContent).toContain('Discard your changes?');
    act(() => button('Discard').click());
    expect(onClose).toHaveBeenCalledTimes(2);
  });

  it('saves into the step’s field, and a new design joins the select’s options', async () => {
    const steps = window.yayMatikMeta.automation.steps as Record<string, unknown>;
    steps.acme_mail = {
      ...meta.automation.steps.send_email,
      fields: [{ key: 'design_id', type: 'select', label: 'Design', options: [{ value: '7', label: 'Spring' }] }],
    };
    const save = vi.fn();
    const Footer = editorFooter('acme_mail', { field: 'design_id', button: 'Customize design' }, 'Send rewards email');
    container = document.body.appendChild(document.createElement('div'));
    root = createRoot(container);
    act(() =>
      root.render(
        <EditorToolsContext.Provider
          value={{ open: async () => ({ url: 'about:blank', sample: { orderId: null, userId: null } }), save }}
        >
          <Footer config={{ design_id: '7' }} />
        </EditorToolsContext.Provider>,
      ),
    );
    expect(container.textContent).toContain('Design changes apply to live emails at once.');
    await act(async () => button('Customize design').click());
    send(document.querySelector('iframe')!, { type: 'save', value: 12, label: 'Summer' });

    expect(save).toHaveBeenCalledWith('design_id', 12);
    expect(window.yayMatikMeta.automation.steps['acme_mail' as StepId]!.fields[0].options).toEqual([
      { value: '7', label: 'Spring' },
      { value: '12', label: 'Summer' },
    ]);
    expect(document.querySelector('iframe')).toBeNull();
    delete steps.acme_mail;
  });
});
