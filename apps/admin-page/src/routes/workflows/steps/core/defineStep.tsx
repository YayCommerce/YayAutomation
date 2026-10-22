import { useState, type ReactNode } from 'react';
import * as v from 'valibot';

import type { CatalogStepMeta } from '@/types/localize';
import { alwaysAvailable } from './data';
import { defaultsFromFields, schemaFromFields } from './fields/catalogSchema';
import { StepForm } from './fields/StepForm';
import { stepIcons } from './icons';
import { MARKED } from './marks';
import { automationMeta, isPendingStep } from './pending';
import { renderSummary } from './summary';
import { templatePaths } from './template';
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

/**
 * A step for the registry. A step PHP has built needs only `id` and `kind`: its schema, defaults
 * and form come from its catalog fields (`StepForm`), unless it passes its own `Form`.
 */
export function defineStep<TConfig extends Config>({
  schema: ownSchema,
  defaults: ownDefaults,
  normalize: ownNormalize,
  uses,
  summary,
  summaryValues,
  icon,
  label = '',
  description = '',
  Form,
  Footer,
  ...spec
}: StepSpec<TConfig>): Step {
  const meta = automationMeta.steps[spec.id];
  const requires = meta.requires ?? [];
  // PHP's catalog entry once the step is built: its wording, icon and summary win over the stub's.
  const catalog = isPendingStep(spec.id) ? undefined : (meta as CatalogStepMeta);
  const fields = catalog?.fields ?? [];

  if (!catalog && (!ownSchema || !ownDefaults)) {
    throw new Error(`Step "${spec.id}" isn't in PHP's catalog yet, so it needs its own schema and defaults.`);
  }
  const schema = ownSchema ?? (schemaFromFields(fields, catalog!.label) as v.GenericSchema<TConfig>);
  const fieldDefaults = defaultsFromFields(fields) as TConfig;
  const defaults = ownDefaults ?? (() => structuredClone(fieldDefaults));
  // A built step keeps only its fields' keys, like PHP `Field::sanitize_all()`.
  const keep = (config: Config) =>
    catalog ? Object.fromEntries(Object.entries(config).filter(([key]) => key in fieldDefaults)) : config;
  const normalize = ownNormalize ?? ((config: Config) => ({ ...defaults(), ...keep(config) }) as TConfig);

  // Fields that accept merge tags; a pending step has no catalog yet, so all its text.
  const variableFields = catalog?.fields.filter((field) => field.variables).map((field) => field.key);
  const tags = (config: Config) =>
    Object.entries(config).flatMap(([field, value]) =>
      typeof value === 'string' && (!variableFields || variableFields.includes(field))
        ? [...new Set(templatePaths(value))].map((path) => ({ field, path }))
        : [],
    );

  const firstIssue = (config: Config) => {
    const result = v.safeParse(schema, normalize(config));
    if (result.success) return null;
    const [{ message, path }] = result.issues;
    const field = path?.[0]?.key;
    return typeof field === 'string' ? { message, field } : { message };
  };

  // Its own form, else one drawn from the fields. Nothing when there's nothing to set.
  const hasForm = !!Form || fields.length > 0 || !!Footer;

  function StepConfigForm({ config, onChange }: StepFormProps<Config>) {
    // Normalized once, so the form's default values keep a stable identity.
    const [initial] = useState(() => normalize(config));
    // The latest values, for the footer.
    const [current, setCurrent] = useState(initial);
    const change = (next: Config) => {
      setCurrent(next as TConfig);
      onChange(next);
    };
    const form = Form ? (
      <Form config={initial} onChange={change as (config: TConfig) => void} />
    ) : (
      <StepForm fields={fields} schema={schema as v.GenericSchema<Config>} config={initial} onChange={change} />
    );
    if (!Footer) return form;
    return (
      <div className="flex flex-col gap-2">
        {form}
        <Footer config={current} />
      </div>
    );
  }

  return {
    ...spec,
    label: catalog?.label ?? label,
    description: catalog?.description ?? description,
    icon: (catalog && stepIcons[catalog.icon]) ?? icon,
    plugin: meta.plugin,
    provides: meta.provides ?? [],
    requires,
    outputs: catalog?.outputs ?? [],
    category: meta.category,
    comingSoon: isPendingStep(spec.id),
    defaults,
    normalize,
    firstIssue,
    firstError: (config) => firstIssue(config)?.message ?? null,
    tags: (config) => tags(normalize(config)),
    // An issue about one of these shows under it; a custom form draws its own fields.
    drawsField: (key) =>
      !Form && fields.some((field) => field.key === key && !['notice', 'rules', 'number_unit'].includes(field.type)),
    uses: (config) => {
      const items = uses ? uses(normalize(config)) : requires;
      return [...new Set(items)].filter((item) => !alwaysAvailable.includes(item));
    },
    summary: (config) =>
      catalog
        ? renderSummary(catalog.summary, catalog.fields, normalize(config), summaryValues)
        : (summary?.(normalize(config)) ?? ''),
    Form: hasForm ? StepConfigForm : undefined,
  };
}
