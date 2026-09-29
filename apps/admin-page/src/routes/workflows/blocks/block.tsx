import { useEffect, useState, type ComponentType, type ReactNode } from 'react';
import type { BlockId, WorkflowNodeKind } from '@yay-automation/types';
import { CircleAlert } from 'lucide-react';
import * as v from 'valibot';

import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';

type Config = Record<string, unknown>;

export interface BlockFormProps<TConfig> {
  /** Initial values. The form owns its state after mount. */
  config: TConfig;
  /** Called on every change, so the workflow draft always holds the latest values. */
  onChange: (config: TConfig) => void;
}

interface BlockSpec<TConfig extends Config> {
  id: BlockId;
  kind: WorkflowNodeKind;
  label: string;
  description: string;
  /**
   * The one source of truth for a valid config: field errors in the form and the workflow's
   * `invalid_config` issue. Must accept exactly what the PHP block's `validate()` accepts.
   */
  schema: v.GenericSchema<TConfig>;
  defaults: () => TConfig;
  /**
   * Brings stored config into the current shape: fills missing keys and upgrades older formats.
   * Mirrors the PHP block's `sanitize()`. Defaults to merging over `defaults()`.
   */
  normalize?: (config: Config) => TConfig;
  /** Plain-language line for the node card. Gets incomplete config too, so show placeholders for gaps. */
  summary: (config: TConfig) => string;
  Form: ComponentType<BlockFormProps<TConfig>>;
}

/** A block with its config type erased, so one registry can hold them all. Every method normalizes first. */
export interface Block {
  id: BlockId;
  kind: WorkflowNodeKind;
  label: string;
  description: string;
  defaults: () => Config;
  normalize: (config: Config) => Config;
  /** The first validation message, or null when the config is valid. */
  firstError: (config: Config) => string | null;
  summary: (config: Config) => string;
  Form: ComponentType<BlockFormProps<Config>>;
}

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

/**
 * Runs the form's change validators once on mount, so an incomplete step shows what's missing as
 * soon as its panel opens instead of after the first edit.
 */
export function useValidateOnMount(form: { validate: (cause: 'change') => unknown }) {
  useEffect(() => {
    form.validate('change');
  }, [form]);
}

/** Label, control, help text and error for one config field. Shows only the first error, with an icon. */
export function ConfigField({
  label,
  htmlFor,
  description,
  errors,
  children,
}: {
  label: string;
  htmlFor: string;
  description?: ReactNode;
  errors: ({ message?: string } | undefined)[];
  children: ReactNode;
}) {
  const error = errors.find((e) => e?.message)?.message;

  return (
    <Field data-invalid={!!error}>
      <FieldLabel htmlFor={htmlFor}>{label}</FieldLabel>
      {children}
      {error && (
        <FieldError className="flex items-center gap-1.5">
          <CircleAlert className="size-3.5 shrink-0" aria-hidden />
          {error}
        </FieldError>
      )}
      {description && <FieldDescription>{description}</FieldDescription>}
    </Field>
  );
}
