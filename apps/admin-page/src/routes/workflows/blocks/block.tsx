import { useEffect, useState, type ComponentType, type ReactNode } from 'react';
import type { BlockId, WorkflowNodeKind } from '@yay-automation/types';
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
   * The one source of truth for a valid config: field errors in the form and the "Needs setup" check.
   * Must accept exactly what the PHP block's `validate()` accepts.
   */
  schema: v.GenericSchema<TConfig>;
  defaults: () => TConfig;
  /** One line shown on the node card. Only called with a valid config. */
  summary: (config: TConfig) => string;
  Form: ComponentType<BlockFormProps<TConfig>>;
}

/** A block with its config type erased, so one registry can hold them all. */
export interface Block {
  id: BlockId;
  kind: WorkflowNodeKind;
  label: string;
  description: string;
  defaults: () => Config;
  isConfigured: (config: Config) => boolean;
  /** Null while the config is invalid. */
  summary: (config: Config) => string | null;
  Form: ComponentType<BlockFormProps<Config>>;
}

export function defineBlock<TConfig extends Config>({
  schema,
  defaults,
  summary,
  Form,
  ...meta
}: BlockSpec<TConfig>): Block {
  function BlockForm({ config, onChange }: BlockFormProps<Config>) {
    // Stored config can miss keys (older data, hand-edited templates). Filled once, so the form's
    // default values keep a stable identity.
    const [initial] = useState(() => ({ ...defaults(), ...config }) as TConfig);
    return <Form config={initial} onChange={onChange as (config: TConfig) => void} />;
  }

  return {
    ...meta,
    defaults,
    isConfigured: (config) => v.is(schema, config),
    summary: (config) => {
      const result = v.safeParse(schema, config);
      return result.success ? summary(result.output) : null;
    },
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

/** Label, control, help text and errors for one config field. */
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
  return (
    <Field data-invalid={errors.length > 0}>
      <FieldLabel htmlFor={htmlFor}>{label}</FieldLabel>
      {children}
      {description && <FieldDescription>{description}</FieldDescription>}
      <FieldError errors={errors} />
    </Field>
  );
}
