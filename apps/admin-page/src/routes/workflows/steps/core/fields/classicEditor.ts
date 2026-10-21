import { isBlankHtml } from './catalogSchema';

/**
 * The WordPress classic editor (TinyMCE + Quicktags) on a textarea, outside React: `mountClassicEditor`
 * adds a textarea to `host` and turns it into an editor with Visual / Code tabs, `destroy()` removes
 * both. The scripts come from PHP `wp_enqueue_editor()`; without TinyMCE (e.g. the user turned the
 * visual editor off) it's the Code tab alone, without the scripts a plain textarea.
 *
 * Stored HTML is what WordPress saves from the editor (paragraphs as blank lines, `wpautop` puts them
 * back). Loading HTML into TinyMCE and saving it again can change its spelling, so `onEdit` only
 * fires on an edit, and an edit that leads back to the loaded content gives the loaded value as it
 * was.
 */

/** The part of a TinyMCE 4 editor used here. */
export interface TinyMceEditor {
  initialized?: boolean;
  removed?: boolean;
  on(events: string, callback: () => void): void;
  isHidden(): boolean;
  save(): string;
  focus(): void;
  insertContent(html: string): void;
  getBody(): HTMLElement;
  selection: {
    getBookmark(type: number, normalized?: boolean): unknown;
    moveToBookmark(bookmark: unknown): void;
    select(node: Node, content?: boolean): void;
    collapse(toStart?: boolean): void;
    /** The element holding the cursor. */
    getNode(): Element;
  };
  undoManager: { transact(change: () => void): void };
}

interface EditorSettings {
  tinymce: false | (Record<string, unknown> & { setup: (editor: TinyMceEditor) => void });
  quicktags: { buttons: string };
  mediaButtons: boolean;
}

/** `wp.oldEditor`, set by WordPress's `editor` script. */
export interface OldEditor {
  initialize(id: string, settings: EditorSettings): void;
  /** Also safe while TinyMCE is still loading: a removed editor never fires `init`. */
  remove(id: string): void;
  getDefaultSettings?: () => unknown;
}

declare global {
  interface Window {
    wp?: { oldEditor?: OldEditor };
    tinymce?: unknown;
  }
}

export interface ClassicEditor {
  /** Puts `text` (not HTML) at the cursor, at the end when the editor was never focused. */
  insert(text: string): void;
  /** As `insert`, as a paragraph of its own: the current one is split around it. */
  insertBlock(text: string): void;
  /** Focuses the editor, the cursor after the last insert. */
  focus(): void;
  destroy(): void;
}

const TOOLBAR =
  'formatselect,bold,italic,underline,bullist,numlist,blockquote,alignleft,aligncenter,link,unlink,undo,redo';

/** Top-level elements a block can't go inside: it goes after them, as a paragraph of its own. */
const NO_BLOCK_INSIDE = /^(UL|OL|H[1-6]|BLOCKQUOTE)$/;

/** The top-level element holding the cursor when it's one of `NO_BLOCK_INSIDE`. */
function outerElement(editor: TinyMceEditor): Element | null {
  const body = editor.getBody();
  let node: Element | null = editor.selection.getNode();
  while (node && node.parentElement !== body) node = node.parentElement;
  return node && NO_BLOCK_INSIDE.test(node.nodeName) ? node : null;
}

const escapeText = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

let nextId = 0;

/** A textarea id unique on the page and jQuery-safe (`wp.oldEditor` looks it up by `#id`). */
export const editorId = (base: string) => `${base.replace(/[^\w-]/g, '-')}-${++nextId}`;

export function mountClassicEditor(
  host: HTMLElement,
  {
    id,
    value,
    onEdit,
    onFocus,
    onBlur,
  }: {
    /** Textarea id, from `editorId`. */
    id: string;
    value: string;
    onEdit: (html: string) => void;
    onFocus?: () => void;
    onBlur?: () => void;
  },
): ClassicEditor {
  const textarea = document.createElement('textarea');
  textarea.id = id;
  textarea.className = 'wp-editor-area';
  textarea.rows = 10;
  textarea.value = value;
  host.append(textarea);

  let editor: TinyMceEditor | null = null;
  // The loaded value as the editor saves it, before any edit; null until TinyMCE is ready.
  let baseline: string | null = null;
  let lastEmitted = value;
  let focused = false;
  // Where the cursor was when the Visual tab lost focus; dropped when its content is loaded again.
  let bookmark: unknown = null;
  let textCursor = 0;

  const visual = () => (editor?.initialized && !editor.removed && !editor.isHidden() ? editor : null);

  const emit = () => {
    const html = visual()?.save() ?? textarea.value;
    const next = html === baseline || html === value ? value : isBlankHtml(html) ? '' : html;
    if (next === lastEmitted) return;
    lastEmitted = next;
    onEdit(next);
  };

  const onTextFocus = () => {
    focused = true;
    onFocus?.();
  };
  const onTextBlur = () => onBlur?.();
  // Quicktags buttons fire `change` only.
  textarea.addEventListener('input', emit);
  textarea.addEventListener('change', emit);
  textarea.addEventListener('focus', onTextFocus);
  textarea.addEventListener('blur', onTextBlur);

  // Missing when the editor scripts aren't on the page (e.g. tests): the textarea is the editor.
  const api = window.wp?.oldEditor?.getDefaultSettings ? window.wp.oldEditor : undefined;
  api?.initialize(id, {
    tinymce: window.tinymce
      ? {
          wpautop: true,
          toolbar1: TOOLBAR,
          toolbar2: '',
          height: 220,
          setup: (instance: TinyMceEditor) => {
            editor = instance;
            instance.on('init', () => {
              baseline = instance.save();
            });
            instance.on('input change undo redo', emit);
            // Switching back from the Code tab loads the content again: an old bookmark may point nowhere.
            instance.on('SetContent', () => (bookmark = null));
            instance.on('focus', () => {
              focused = true;
              onFocus?.();
            });
            instance.on('blur', () => {
              bookmark = instance.selection.getBookmark(2, true);
              onBlur?.();
            });
          },
        }
      : false,
    quicktags: { buttons: 'strong,em,link,ul,ol,li,close' },
    mediaButtons: false,
  });

  /**
   * At the cursor: `visualPut` in the Visual tab (it leaves the cursor after what it added), else
   * `text(before, after)` into the Code tab.
   */
  const put = (visualPut: (mce: TinyMceEditor) => void, text: (before: string, after: string) => string) => {
    const mce = visual();
    if (mce) {
      if (bookmark) mce.selection.moveToBookmark(bookmark);
      else if (!focused) {
        mce.selection.select(mce.getBody(), true);
        mce.selection.collapse(false);
      }
      visualPut(mce);
      bookmark = mce.selection.getBookmark(2, true);
    } else {
      const start = focused ? textarea.selectionStart : textarea.value.length;
      const end = focused ? textarea.selectionEnd : textarea.value.length;
      const before = textarea.value.slice(0, start);
      const after = textarea.value.slice(end);
      const added = text(before, after);
      textarea.value = before + added + after;
      textCursor = start + added.length;
    }
    focused = true;
    emit();
  };

  return {
    insert(text) {
      put(
        (mce) => mce.insertContent(escapeText(text)),
        () => text,
      );
    },
    insertBlock(text) {
      const visualPut = (mce: TinyMceEditor) => {
        const outer = outerElement(mce);
        if (!outer) return mce.insertContent(`<p>${escapeText(text)}</p>`);
        // In a list, heading or quote, `insertContent` would nest the paragraph there.
        const paragraph = outer.ownerDocument.createElement('p');
        paragraph.textContent = text;
        mce.undoManager.transact(() => outer.after(paragraph));
        mce.selection.select(paragraph, true);
        mce.selection.collapse(false);
      };
      // Code tab: paragraphs are blank lines (`wpautop`), so one on each side, counting the line breaks already there.
      const gap = (side: string, breaks: RegExp) => (side === '' ? '' : '\n\n'.slice(side.match(breaks)![0].length));
      put(visualPut, (before, after) => gap(before, /\n{0,2}$/) + text + gap(after, /^\n{0,2}/));
    },
    focus() {
      const mce = visual();
      if (mce) {
        mce.focus();
        if (bookmark) mce.selection.moveToBookmark(bookmark);
        return;
      }
      textarea.focus();
      textarea.setSelectionRange(textCursor, textCursor);
    },
    destroy() {
      textarea.removeEventListener('input', emit);
      textarea.removeEventListener('change', emit);
      textarea.removeEventListener('focus', onTextFocus);
      textarea.removeEventListener('blur', onTextBlur);
      api?.remove(id);
      host.replaceChildren();
    },
  };
}
