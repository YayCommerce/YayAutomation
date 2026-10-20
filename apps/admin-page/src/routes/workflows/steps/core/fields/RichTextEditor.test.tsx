import { act, StrictMode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { isBlankHtml } from './catalogSchema';
import { mountClassicEditor, type OldEditor, type TinyMceEditor } from './classicEditor';
import { RichTextEditor } from './RichTextEditor';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

/**
 * Stand-in for TinyMCE: the content is a string with a cursor; `save()` spells it the way the real
 * editor may (`<br>` → `<br />`), so a load + save without an edit isn't the stored string.
 */
class FakeTinyMce implements TinyMceEditor {
  initialized = false;
  removed = false;
  hidden = false;
  content = '';
  cursor = 0;
  focused = false;
  private handlers: Record<string, (() => void)[]> = {};
  selection = {
    getBookmark: () => this.cursor,
    moveToBookmark: (bookmark: unknown) => void (this.cursor = bookmark as number),
    select: () => {},
    collapse: () => void (this.cursor = this.content.length),
  };
  constructor(readonly textarea: HTMLTextAreaElement) {
    this.content = textarea.value;
  }
  on(events: string, callback: () => void) {
    for (const event of events.split(' ')) (this.handlers[event] ??= []).push(callback);
  }
  fire(event: string) {
    for (const callback of this.handlers[event] ?? []) callback();
  }
  /** What a user typing does. */
  type(content: string) {
    this.content = content;
    this.fire('input');
  }
  isHidden = () => this.hidden;
  save = () => (this.textarea.value = this.content.replace(/<br>/g, '<br />'));
  focus = () => void (this.focused = true);
  insertContent = (html: string) => {
    this.content = this.content.slice(0, this.cursor) + html + this.content.slice(this.cursor);
    this.cursor += html.length;
  };
  getBody = () => document.body;
}

/**
 * `wp.oldEditor` with fake TinyMCE editors; `ready: false` leaves them loading until `finishInit`,
 * `tinymce: false` is a user with the visual editor turned off.
 */
function installOldEditor({ ready = true, tinymce = true } = {}) {
  const editors = new Map<string, FakeTinyMce>();
  const api: OldEditor = {
    getDefaultSettings: () => ({}),
    initialize: vi.fn((id, settings) => {
      if (!settings.tinymce) return;
      const editor = new FakeTinyMce(document.getElementById(id) as HTMLTextAreaElement);
      editors.set(id, editor);
      settings.tinymce.setup(editor);
      if (ready) finishInit(editor);
    }),
    remove: vi.fn((id) => {
      const editor = editors.get(id);
      if (editor) editor.removed = true;
      editors.delete(id);
    }),
  };
  // Like TinyMCE: a removed editor fires nothing.
  const finishInit = (editor: FakeTinyMce) => {
    if (editor.removed) return;
    editor.initialized = true;
    editor.fire('init');
  };
  window.wp = { oldEditor: api };
  if (tinymce) window.tinymce = {};
  return { api, editors, finishInit, only: () => [...editors.values()][0] };
}

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
  delete window.wp;
  delete window.tinymce;
});

const field = (props: { value: string; onChange?: (value: string) => void }) => (
  <RichTextEditor
    id="step-field-message"
    label="Message"
    variables
    value={props.value}
    onChange={props.onChange ?? (() => {})}
    onBlur={() => {}}
    invalid={false}
    errors={[]}
  />
);

describe('RichTextEditor', () => {
  it('starts the editor on a textarea holding the value and removes it on unmount', () => {
    const { api } = installOldEditor();
    act(() => root.render(field({ value: '<p>Hi</p>' })));

    expect(api.initialize).toHaveBeenCalledTimes(1);
    const id = vi.mocked(api.initialize).mock.calls[0][0];
    expect((document.getElementById(id) as HTMLTextAreaElement).value).toBe('<p>Hi</p>');
    expect(container.querySelector('label')?.htmlFor).toBe(id);

    act(() => root.unmount());
    expect(api.remove).toHaveBeenCalledWith(id);
    expect(document.getElementById(id)).toBeNull();
    root = createRoot(container);
  });

  it('leaves one editor after the StrictMode double mount', () => {
    const { api, editors } = installOldEditor();
    act(() => root.render(<StrictMode>{field({ value: 'Hi' })}</StrictMode>));

    expect(api.initialize).toHaveBeenCalledTimes(2);
    expect(api.remove).toHaveBeenCalledTimes(1);
    expect(editors.size).toBe(1);
    expect(container.querySelectorAll('textarea')).toHaveLength(1);
  });

  it('removes an editor closed while TinyMCE is still loading', () => {
    const { api, only, finishInit } = installOldEditor({ ready: false });
    const onChange = vi.fn();
    act(() => root.render(field({ value: 'Hi', onChange })));
    const editor = only();

    act(() => root.unmount());
    expect(api.remove).toHaveBeenCalledTimes(1);
    expect(container.querySelector('textarea')).toBeNull();
    finishInit(editor);
    expect(editor.initialized).toBe(false);
    root = createRoot(container);
  });

  it('keeps the stored value when the editor reports a change that is no edit', () => {
    const { only } = installOldEditor();
    const onChange = vi.fn();
    act(() => root.render(field({ value: 'Line<br>two', onChange })));

    only().fire('change');
    expect(onChange).not.toHaveBeenCalled();
  });

  it('gives the stored value back when an edit is undone', () => {
    const { only } = installOldEditor();
    const onChange = vi.fn();
    act(() => root.render(field({ value: 'Line<br>two', onChange })));
    const editor = only();

    act(() => editor.type('Line<br>two!'));
    expect(onChange).toHaveBeenLastCalledWith('Line<br />two!');
    act(() => editor.type('Line<br>two'));
    expect(onChange).toHaveBeenLastCalledWith('Line<br>two');
  });

  it('saves an emptied editor as blank', () => {
    const { only } = installOldEditor();
    const onChange = vi.fn();
    act(() => root.render(field({ value: 'Hi', onChange })));

    act(() => only().type('<p>&nbsp;</p>'));
    expect(onChange).toHaveBeenLastCalledWith('');
  });

  it('loads a value set from outside again', () => {
    const { api } = installOldEditor();
    act(() => root.render(field({ value: 'One' })));
    act(() => root.render(field({ value: 'Two' })));

    expect(api.remove).toHaveBeenCalledTimes(1);
    expect(api.initialize).toHaveBeenCalledTimes(2);
    expect(container.querySelector('textarea')?.value).toBe('Two');
  });

  it('catches Quicktags edits, which fire change only', () => {
    installOldEditor({ tinymce: false });
    const onChange = vi.fn();
    act(() => root.render(field({ value: 'Hi', onChange })));
    const textarea = container.querySelector('textarea') as HTMLTextAreaElement;

    textarea.value = '<strong>Hi</strong>';
    act(() => void textarea.dispatchEvent(new Event('change')));
    expect(onChange).toHaveBeenLastCalledWith('<strong>Hi</strong>');
  });

  it('is a plain textarea without the editor scripts', () => {
    const onChange = vi.fn();
    act(() => root.render(field({ value: 'Hi', onChange })));
    const textarea = container.querySelector('textarea') as HTMLTextAreaElement;

    textarea.value = 'Hi there';
    act(() => void textarea.dispatchEvent(new Event('input')));
    expect(onChange).toHaveBeenLastCalledWith('Hi there');
  });
});

describe('mountClassicEditor insert', () => {
  const tag = '{{ customer.first_name }}';
  let host: HTMLDivElement;
  beforeEach(() => {
    host = document.createElement('div');
    document.body.append(host);
  });
  afterEach(() => host.remove());

  it('inserts at the cursor in the Visual tab', () => {
    const { only } = installOldEditor();
    const onEdit = vi.fn();
    const editor = mountClassicEditor(host, { id: 'e1', value: 'Hi !', onEdit });
    const mce = only();
    mce.fire('focus');
    mce.cursor = 3;
    mce.fire('blur');

    editor.insert(tag);
    expect(onEdit).toHaveBeenLastCalledWith(`Hi ${tag}!`);
    editor.focus();
    expect(mce.focused).toBe(true);
    editor.destroy();
  });

  it('drops the bookmark when the content is loaded again (back from the Code tab)', () => {
    const { only } = installOldEditor();
    const onEdit = vi.fn();
    const editor = mountClassicEditor(host, { id: 'e5', value: 'Hi !', onEdit });
    const mce = only();
    mce.fire('focus');
    mce.cursor = 3;
    mce.fire('blur');
    mce.content = 'Hello';
    mce.cursor = 5;
    mce.fire('SetContent');

    editor.insert(tag);
    expect(onEdit).toHaveBeenLastCalledWith(`Hello${tag}`);
    editor.destroy();
  });

  it('inserts at the end of a Visual tab never focused', () => {
    installOldEditor();
    const onEdit = vi.fn();
    const editor = mountClassicEditor(host, { id: 'e2', value: 'Hi ', onEdit });

    editor.insert(tag);
    expect(onEdit).toHaveBeenLastCalledWith(`Hi ${tag}`);
    editor.destroy();
  });

  it('escapes the inserted text as HTML in the Visual tab', () => {
    installOldEditor();
    const onEdit = vi.fn();
    const editor = mountClassicEditor(host, { id: 'e3', value: '', onEdit });

    editor.insert('a < b');
    expect(onEdit).toHaveBeenLastCalledWith('a &lt; b');
    editor.destroy();
  });

  it('inserts at the cursor in the Code tab and puts the cursor after it', () => {
    const { only } = installOldEditor();
    const onEdit = vi.fn();
    const editor = mountClassicEditor(host, { id: 'e4', value: '<p>Hi !</p>', onEdit });
    only().hidden = true;
    const textarea = host.querySelector('textarea') as HTMLTextAreaElement;
    textarea.dispatchEvent(new Event('focus'));
    textarea.setSelectionRange(6, 6);

    editor.insert(tag);
    expect(onEdit).toHaveBeenLastCalledWith(`<p>Hi ${tag}!</p>`);
    editor.focus();
    expect(textarea.selectionStart).toBe(6 + tag.length);
    editor.destroy();
  });
});

describe('isBlankHtml', () => {
  it.each(['', '<p>&nbsp;</p>', '<p><br data-mce-bogus="1"></p>', ' \n', '\u00a0', '<hr>'])('%j is blank', (html) =>
    expect(isBlankHtml(html)).toBe(true),
  );
  it.each(['Hi', '<p><img src="x.png"></p>'])('%j is not blank', (html) => expect(isBlankHtml(html)).toBe(false));
});
