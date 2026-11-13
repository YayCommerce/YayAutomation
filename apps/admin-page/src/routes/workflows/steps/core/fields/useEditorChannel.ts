import { useEffect, useRef, type RefObject } from 'react';
import { __ } from '@wordpress/i18n';

/** postMessage schema v1 (docs/extension-api.md §5.6). */
export const EDITOR_SOURCE = 'yaymatik-editor';
export const EDITOR_VERSION = 1;
/** A value the step keeps: it fits a 191-character column. */
export const MAX_VALUE_LENGTH = 191;

/** What an editor page may send; any other `type` is ignored. */
export type EditorMessage =
  | { type: 'ready' }
  | { type: 'change'; value: unknown; dirty: boolean }
  | { type: 'save'; value: string | number; label?: string }
  | { type: 'cancel' }
  | { type: 'error'; message: string };

/** The message from this iframe, origin, channel and version, else null; an unusable `save` value reads as an `error`. */
export function readEditorMessage(
  event: Pick<MessageEvent, 'origin' | 'source' | 'data'>,
  frame: Window | null | undefined,
  channel: string,
): EditorMessage | null {
  const data = event.data as Record<string, unknown> | null;
  if (
    event.origin !== window.location.origin ||
    !frame ||
    event.source !== frame ||
    typeof data !== 'object' ||
    data === null ||
    data.source !== EDITOR_SOURCE ||
    data.version !== EDITOR_VERSION ||
    data.channel !== channel
  ) {
    return null;
  }
  switch (data.type) {
    case 'ready':
    case 'cancel':
      return { type: data.type };
    case 'change':
      return { type: 'change', value: data.value, dirty: data.dirty === true };
    case 'save': {
      const { value } = data;
      const fits =
        (typeof value === 'string' || (typeof value === 'number' && Number.isFinite(value))) &&
        String(value).length <= MAX_VALUE_LENGTH;
      if (!fits) return { type: 'error', message: __('The editor sent a value that can’t be saved.', 'yaymatik') };
      return { type: 'save', value, ...(typeof data.label === 'string' ? { label: data.label } : {}) };
    }
    case 'error':
      return { type: 'error', message: typeof data.message === 'string' ? data.message : '' };
    default:
      return null;
  }
}

/** The iframe's messages on `channel`: `ready` is answered with `init` (to this origin only), the rest go to `onMessage`. */
export function useEditorChannel(
  frame: RefObject<HTMLIFrameElement | null>,
  channel: string,
  init: Record<string, unknown>,
  onMessage: (message: Exclude<EditorMessage, { type: 'ready' }>) => void,
) {
  // The latest values, so the listener isn't re-added on every render.
  const latest = useRef({ init, onMessage });
  useEffect(() => {
    latest.current = { init, onMessage };
  });

  useEffect(() => {
    const listen = (event: MessageEvent) => {
      const target = frame.current?.contentWindow;
      const message = readEditorMessage(event, target, channel);
      if (!message) return;
      if (message.type !== 'ready') {
        latest.current.onMessage(message);
        return;
      }
      target?.postMessage(
        { source: EDITOR_SOURCE, version: EDITOR_VERSION, channel, type: 'init', ...latest.current.init },
        window.location.origin,
      );
    };
    window.addEventListener('message', listen);
    return () => window.removeEventListener('message', listen);
  }, [frame, channel]);
}
