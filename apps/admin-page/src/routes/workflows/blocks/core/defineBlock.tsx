import { useState } from 'react';
import * as v from 'valibot';

import { alwaysAvailable, itemOf, variableKeys } from './data';
import type { Block, BlockFormProps, BlockSpec, Config } from './types';

/** Shown in summaries where a required value is still missing. */
export const PLACEHOLDER = '…';

export function defineBlock<TConfig extends Config>({
  schema,
  defaults,
  normalize = (config) => ({ ...defaults(), ...config }) as TConfig,
  uses,
  summary,
  Form,
  ...spec
}: BlockSpec<TConfig>): Block {
  const meta = window.yayAutomationMeta.automation.blocks[spec.id];
  const requires = meta.requires ?? [];

  /** Default `uses`: what the action always needs, plus the items of its merge tags. */
  const usesByDefault = (config: TConfig) => [
    ...requires,
    ...Object.values(config).flatMap((value) => (typeof value === 'string' ? variableKeys(value).map(itemOf) : [])),
  ];

  function BlockForm({ config, onChange, available }: BlockFormProps<Config>) {
    // Normalized once, so the form's default values keep a stable identity.
    const [initial] = useState(() => normalize(config));
    return Form && <Form config={initial} onChange={onChange as (config: TConfig) => void} available={available} />;
  }

  return {
    ...spec,
    plugin: meta.plugin,
    provides: meta.provides ?? [],
    requires,
    category: meta.category,
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
