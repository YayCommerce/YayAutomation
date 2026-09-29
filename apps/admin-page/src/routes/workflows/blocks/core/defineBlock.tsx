import { useState } from 'react';
import * as v from 'valibot';

import type { Block, BlockFormProps, BlockSpec, Config } from './types';

/** Shown in summaries where a required value is still missing. */
export const PLACEHOLDER = '…';

export function defineBlock<TConfig extends Config>({
  schema,
  defaults,
  normalize = (config) => ({ ...defaults(), ...config }) as TConfig,
  summary,
  Form,
  ...meta
}: BlockSpec<TConfig>): Block {
  function BlockForm({ config, onChange }: BlockFormProps<Config>) {
    // Normalized once, so the form's default values keep a stable identity.
    const [initial] = useState(() => normalize(config));
    return <Form config={initial} onChange={onChange as (config: TConfig) => void} />;
  }

  return {
    ...meta,
    defaults,
    normalize,
    firstError: (config) => {
      const result = v.safeParse(schema, normalize(config));
      return result.success ? null : result.issues[0].message;
    },
    summary: (config) => summary(normalize(config)),
    Form: BlockForm,
  };
}
