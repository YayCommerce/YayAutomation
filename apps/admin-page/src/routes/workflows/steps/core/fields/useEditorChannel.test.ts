import { describe, expect, it } from 'vitest';

import { readEditorMessage } from './useEditorChannel';

describe('readEditorMessage', () => {
  const frame = {} as Window;
  const message = (data: Record<string, unknown>, over: Partial<{ origin: string; source: unknown }> = {}) =>
    readEditorMessage(
      {
        origin: window.location.origin,
        source: frame as MessageEventSource,
        data: { source: 'yaymatik-editor', version: 1, channel: 'abc', ...data },
        ...over,
      } as MessageEvent,
      frame,
      'abc',
    );

  it('reads a message from this iframe, on this site, for this channel and version', () => {
    expect(message({ type: 'ready' })).toEqual({ type: 'ready' });
    expect(message({ type: 'change', value: 7, dirty: true })).toEqual({ type: 'change', value: 7, dirty: true });
    expect(message({ type: 'save', value: '12', label: 'Spring' })).toEqual({
      type: 'save',
      value: '12',
      label: 'Spring',
    });
    expect(message({ type: 'error', message: 'Lost connection.' })).toEqual({
      type: 'error',
      message: 'Lost connection.',
    });
  });

  it('ignores another origin, window, channel, version or sender, and unknown types', () => {
    expect(message({ type: 'ready' }, { origin: 'https://evil.test' })).toBeNull();
    expect(message({ type: 'ready' }, { source: {} })).toBeNull();
    expect(message({ type: 'ready', channel: 'other' })).toBeNull();
    expect(message({ type: 'ready', version: 2 })).toBeNull();
    expect(message({ type: 'ready', source: 'someone-else' })).toBeNull();
    expect(message({ type: 'resize' })).toBeNull();
    expect(readEditorMessage({ origin: window.location.origin, source: null, data: null }, frame, 'abc')).toBeNull();
  });

  it('refuses a saved value longer than 191 characters, or one that is not a string or number', () => {
    expect(message({ type: 'save', value: 'x'.repeat(191) })).toEqual({ type: 'save', value: 'x'.repeat(191) });
    for (const value of ['x'.repeat(192), { id: 1 }, null, Number.NaN]) {
      expect(message({ type: 'save', value })).toEqual({
        type: 'error',
        message: 'The editor sent a value that can’t be saved.',
      });
    }
  });
});
