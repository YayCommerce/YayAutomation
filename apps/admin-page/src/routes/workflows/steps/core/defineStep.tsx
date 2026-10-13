import { useState, type ReactNode } from 'react';
import * as v from 'valibot';

import type { CatalogStepMeta } from '@/types/localize';
import { alwaysAvailable, itemOf, variableKeys } from './data';
import { stepIcons } from './icons';
import { MARKED } from './marks';
import { automationMeta, isPendingStep } from './pending';
import { renderSummary } from './summary';
import type { Config, Step, StepFormProps, StepSpec } from './types';

export { PLACEHOLDER, plainSummary, value } from './marks';

/** A step summary with its values in bold and its placeholders in italics. */
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

export function defineStep<TConfig extends Config>({
  schema,
  defaults,
  normalize = (config) => ({ ...defaults(), ...config }) as TConfig,
  uses,
  summary,
  summaryValues,
  icon,
  label = '',
  description = '',
  Form,
  ...spec
}: StepSpec<TConfig>): Step {
  const meta = automationMeta.steps[spec.id];
  const requires = meta.requires ?? [];
  // PHP's catalog entry once the step is built: its wording, icon and summary win over the stub's.
  const catalog = isPendingStep(spec.id) ? undefined : (meta as CatalogStepMeta);

  /** Default `uses`: what the action always needs, plus the items of its merge tags. */
  const usesByDefault = (config: TConfig) => [
    ...requires,
    ...Object.values(config).flatMap((value) => (typeof value === 'string' ? variableKeys(value).map(itemOf) : [])),
  ];

  function StepConfigForm({ config, onChange }: StepFormProps<Config>) {
    // Normalized once, so the form's default values keep a stable identity.
    const [initial] = useState(() => normalize(config));
    return Form && <Form config={initial} onChange={onChange as (config: TConfig) => void} />;
  }

  return {
    ...spec,
    label: catalog?.label ?? label,
    description: catalog?.description ?? description,
    icon: (catalog && stepIcons[catalog.icon]) ?? icon,
    plugin: meta.plugin,
    provides: meta.provides ?? [],
    requires,
    category: meta.category,
    comingSoon: isPendingStep(spec.id),
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
    summary: (config) =>
      catalog
        ? renderSummary(catalog.summary, catalog.fields, normalize(config), summaryValues)
        : (summary?.(normalize(config)) ?? ''),
    Form: Form && StepConfigForm,
  };
}
