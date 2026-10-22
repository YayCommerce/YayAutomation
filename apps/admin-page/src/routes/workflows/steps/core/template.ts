import { sprintf } from '@wordpress/i18n';

import { messages } from '../../model/messages';

/**
 * Merge tags in step text: `{{ path | filter: arg, arg }}`. Parses only (checks and autocomplete);
 * PHP renders. Mirrors includes/Workflow/Core/Data/Template.php: same grammar, same messages. Shared
 * cases in tests/fixtures/template-cases.json.
 */

// A tag can't hold `{{`, so a stray `{{` doesn't swallow the next tag; with `{{{ x }}}` the inner
// `{{ x }}` is the tag.
const TAG = /\{\{(?!\{)((?:(?!\{\{)[\s\S])*?)\}\}/g;

const PATH = /^[a-z_][a-z0-9_]*(?:\.[A-Za-z0-9_-]+)+$/;

const FILTER_NAME = /^[a-z_][a-z0-9_]*$/;

const NUMBER = /^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/;

/** PHP `trim()`: ASCII whitespace only. `String.trim()` also strips e.g. a no-break space, which PHP keeps (a malformed tag there). */
const PHP_SPACE = ' \t\n\r\0\x0B';
function trim(text: string) {
  let start = 0;
  let end = text.length;
  while (start < end && PHP_SPACE.includes(text[start])) start++;
  while (end > start && PHP_SPACE.includes(text[end - 1])) end--;
  return text.slice(start, end);
}

const ESCAPES: Record<string, string> = { n: '\n', t: '\t', r: '\r', v: '\v', f: '\f', a: '\x07', b: '\b' };

/** PHP `stripcslashes()`: C escapes (`\n`, `\x41`, octal `\101`), else the character itself. */
const unescape = (text: string) =>
  text.replace(/\\(x[0-9a-fA-F]{1,2}|[0-7]{1,3}|[\s\S])/g, (_, escape: string) =>
    escape[0] === 'x' && escape.length > 1
      ? String.fromCharCode(parseInt(escape.slice(1), 16))
      : /^[0-7]/.test(escape)
        ? String.fromCharCode(parseInt(escape, 8) & 0xff)
        : (ESCAPES[escape] ?? escape),
  );

/** PHP `Filters::names()`, in registration order. */
const FILTERS = ['default', 'upper', 'lower', 'join', 'date', 'money'];

interface TemplateFilter {
  name: string;
  args: (string | number)[];
}

export type TemplateToken =
  | { type: 'text'; text: string }
  /** `error` is null for a well-formed tag; `path` is '' otherwise. */
  | { type: 'tag'; raw: string; path: string; filters: TemplateFilter[]; error: string | null };

/** Text and tags, in order. */
export function parseTemplate(text: string): TemplateToken[] {
  const tokens: TemplateToken[] = [];
  let last = 0;
  for (const match of text.matchAll(TAG)) {
    if (match.index > last) tokens.push({ type: 'text', text: text.slice(last, match.index) });
    tokens.push(parseTag(match[1]));
    last = match.index + match[0].length;
  }
  if (last < text.length) tokens.push({ type: 'text', text: text.slice(last) });
  return tokens;
}

/** Paths of the well-formed tags, in order, without duplicates. */
export const templatePaths = (text: string) => [
  ...new Set(
    parseTemplate(text).flatMap((token) => (token.type === 'tag' && token.error === null ? [token.path] : [])),
  ),
];

/** Problems in the text: malformed tags and unknown filters. One message each. */
export function templateErrors(text: string): string[] {
  const errors: string[] = [];
  for (const token of parseTemplate(text)) {
    if (token.type !== 'tag') continue;
    if (token.error !== null) {
      errors.push(token.error);
      continue;
    }
    for (const filter of token.filters) {
      if (!FILTERS.includes(filter.name)) {
        errors.push(sprintf(messages.unknownFilter, filter.name, FILTERS.join(', ')));
      }
    }
  }
  return errors;
}

/** `body`: what's between `{{` and `}}`. */
function parseTag(body: string): TemplateToken {
  const raw = `{{${body}}}`;
  const malformed: TemplateToken = {
    type: 'tag',
    raw,
    path: '',
    filters: [],
    error: sprintf(messages.malformedTag, raw),
  };

  const sections = splitOutsideQuotes(body, '|');
  if (!sections) return malformed;
  const path = trim(sections.shift()!);
  if (!PATH.test(path)) return malformed;

  const filters: TemplateFilter[] = [];
  for (const section of sections) {
    const colon = section.indexOf(':');
    const name = trim(colon === -1 ? section : section.slice(0, colon));
    const args = colon === -1 ? [] : parseArgs(section.slice(colon + 1));
    if (!FILTER_NAME.test(name) || !args) return malformed;
    filters.push({ name, args });
  }
  return { type: 'tag', raw, path, filters, error: null };
}

/** `"text", 'text', 3, 1.5` → values. Null when an argument isn't a quoted string or a number. */
function parseArgs(text: string): (string | number)[] | null {
  const pieces = splitOutsideQuotes(text, ',');
  if (!pieces) return null;
  const args: (string | number)[] = [];
  for (const piece of pieces.map(trim)) {
    const quoted = /^"((?:[^"\\]|\\[\s\S])*)"$/.exec(piece) ?? /^'((?:[^'\\]|\\[\s\S])*)'$/.exec(piece);
    if (quoted) {
      args.push(unescape(quoted[1]));
    } else if (NUMBER.test(piece)) {
      args.push(Number(piece));
    } else {
      return null;
    }
  }
  return args;
}

/** Splits on a one-character separator outside quotes. Null when a quote isn't closed. */
function splitOutsideQuotes(text: string, separator: string): string[] | null {
  const pieces: string[] = [];
  let current = '';
  let quote: string | null = null;
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (quote !== null) {
      current += char;
      if (char === '\\' && i + 1 < text.length) {
        current += text[++i];
      } else if (char === quote) {
        quote = null;
      }
    } else if (char === '"' || char === "'") {
      quote = char;
      current += char;
    } else if (char === separator) {
      pieces.push(current);
      current = '';
    } else {
      current += char;
    }
  }
  if (quote !== null) return null;
  pieces.push(current);
  return pieces;
}
