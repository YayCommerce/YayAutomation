import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

import { fillTemplate, parseTemplate, templateErrors, templatePaths } from './template';

/** Shared with PHP `TemplateTest`: same text, same errors and paths. */
const { cases } = JSON.parse(
  readFileSync(resolve(__dirname, '../../../../../../../tests/fixtures/template-cases.json'), 'utf8'),
) as { cases: { text: string; errors: string[]; paths: string[] }[] };

describe('template parser', () => {
  it.each(cases.map((c) => [c.text, c] as const))('%j', (_text, { text, errors, paths }) => {
    expect(templateErrors(text)).toEqual(errors);
    expect(templatePaths(text)).toEqual(paths);
  });

  it('keeps the text around tags and reads filter arguments', () => {
    expect(parseTemplate('Hi {{ customer.first_name | default: "the\\"re", 2 }}!')).toEqual([
      { type: 'text', text: 'Hi ' },
      {
        type: 'tag',
        raw: '{{ customer.first_name | default: "the\\"re", 2 }}',
        path: 'customer.first_name',
        filters: [{ name: 'default', args: ['the"re', 2] }],
        error: null,
      },
      { type: 'text', text: '!' },
    ]);
  });

  it('fills placeholders by name, never parsing a value again, and keeps unknown ones', () => {
    const values: Record<string, string> = { step: 'Note {{ step }}', 'config.to': 'a@x.com' };
    expect(fillTemplate('{{ step }} to {{config.to}}, {{ config.to | upper }} {{ x.y }}', (name) => values[name])).toBe(
      'Note {{ step }} to a@x.com, {{ config.to | upper }} {{ x.y }}',
    );
  });

  it('reads C escapes in quoted arguments, like PHP stripcslashes()', () => {
    const [tag] = parseTemplate('{{ order.number | default: "a\\nb\\x41\\101\\q" }}');
    expect(tag).toMatchObject({ filters: [{ name: 'default', args: ['a\nbAAq'] }] });
  });
});
