import { useState, type ReactNode } from 'react';
import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import { alwaysAvailable, itemOf, variableKeys } from './data';
import { automationMeta, isPendingBlock } from './pending';
import type { Block, BlockFormProps, BlockSpec, Config } from './types';

// Invisible characters around a part of a summary mark how `SummaryText` shows it, so summaries
// stay plain strings (sprintf, i18n) yet render rich.
const EMPTY_MARK = '\u2060';
const VALUE_MARK = '\u2061';

/** Shown in summaries where a required value is still missing: an italic "Empty", like Shopify Flow. */
export const PLACEHOLDER = `${EMPTY_MARK}${__('Empty', 'yay-automation')}${EMPTY_MARK}`;

/** A configured value in a summary, shown bold like Shopify Flow's; blank → the italic placeholder. */
export const value = (text: string) => (text.trim() ? `${VALUE_MARK}${text}${VALUE_MARK}` : PLACEHOLDER);

const MARKED = new RegExp(
  `${EMPTY_MARK}([^${EMPTY_MARK}]*)${EMPTY_MARK}|${VALUE_MARK}([^${VALUE_MARK}]*)${VALUE_MARK}`,
  'g',
);

/** A block summary with its values in bold and its placeholders in italics. */
export function SummaryText({ text }: { text: string }) {
  const parts: ReactNode[] = [];
  let last = 0;
  for (const match of text.matchAll(MARKED)) {
    parts.push(text.slice(last, match.index));
    parts.push(
      match[1] !== undefined ? (
        <em key={match.index}>{match[1]}</em>
      ) : (
        <strong key={match.index} className="font-semibold">
          {match[2]}
        </strong>
      ),
    );
    last = match.index + match[0].length;
  }
  parts.push(text.slice(last));
  return parts;
}

export function defineBlock<TConfig extends Config>({
  schema,
  defaults,
  normalize = (config) => ({ ...defaults(), ...config }) as TConfig,
  uses,
  summary,
  Form,
  ...spec
}: BlockSpec<TConfig>): Block {
  const meta = automationMeta.blocks[spec.id];
  const requires = meta.requires ?? [];

  /** Default `uses`: what the action always needs, plus the items of its merge tags. */
  const usesByDefault = (config: TConfig) => [
    ...requires,
    ...Object.values(config).flatMap((value) => (typeof value === 'string' ? variableKeys(value).map(itemOf) : [])),
  ];

  function BlockForm({ config, onChange }: BlockFormProps<Config>) {
    // Normalized once, so the form's default values keep a stable identity.
    const [initial] = useState(() => normalize(config));
    return Form && <Form config={initial} onChange={onChange as (config: TConfig) => void} />;
  }

  return {
    ...spec,
    plugin: meta.plugin,
    provides: meta.provides ?? [],
    requires,
    category: meta.category,
    comingSoon: isPendingBlock(spec.id),
    defaults,
    normalize,
    firstError: (config) => {
      const result = v.safeParse(schema, normalize(config));
      return result.success ? null : result.issues[0].message;
    },
    uses: (config) => {
      const items = (uses ?? usesByDefault)(normalize(config));
      return [...new Set(items)].filter((item) => !alwaysAvailable.includes(item));
    },
    summary: (config) => summary(normalize(config)),
    Form: Form && BlockForm,
  };
}
