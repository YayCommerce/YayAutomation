import type { ComponentType } from 'react';
import type { StepId, WorkflowNodeKind, WorkflowPlugin } from '@yaymatik/types';
import type { LucideIcon } from 'lucide-react';
import type * as v from 'valibot';

import type { CatalogStepMeta, StepMeta, StepOutputMeta } from '@/types/localize';
import type { SummaryValues } from './summary';

export type Config = Record<string, unknown>;

export interface StepFormProps<TConfig> {
  /** Initial values. The form owns its state after mount. */
  config: TConfig;
  onChange: (config: TConfig) => void;
}

/** What a step folder passes to `defineStep()`. */
export interface StepSpec<TConfig extends Config> {
  id: StepId;
  kind: WorkflowNodeKind;
  /** For rules the catalog fields can't express (the PHP step's own `validate()`). */
  schema?: v.GenericSchema<TConfig>;
  /** A built step passes these only for sample text PHP doesn't keep. */
  defaults?: () => TConfig;
  /** Mirrors the PHP step's `sanitize()`. */
  normalize?: (config: Config) => TConfig;
  /** Data items the step reads besides its merge tags. Mirrors the PHP step's `uses()`. */
  uses?: (config: TConfig) => string[];
  /** Checks that need the workflow's trigger (its step id). Mirrors the PHP step's `context_error()`. */
  contextIssue?: (config: TConfig, trigger: string) => { message: string; field?: string } | null;
  /** Catalog summary placeholders a field can't write on its own (e.g. a condition's `%rules%`). */
  summaryValues?: SummaryValues<TConfig>;
  /** A custom form, for what catalog fields can't draw. */
  Form?: ComponentType<StepFormProps<TConfig>>;
  /** Drawn under the form with its latest values. */
  Footer?: ComponentType<{ config: TConfig }>;
  /** Drawn in the panel header under the title. */
  Actions?: ComponentType;
}

/** A step with its config type erased. Every method normalizes first. */
export interface Step {
  id: StepId;
  kind: WorkflowNodeKind;
  label: string;
  description: string;
  plugin?: WorkflowPlugin;
  /** A trigger's data items for every run, an action's for the steps below it. */
  provides: string[];
  /** Data items needed whatever the config; the picker dims the action without them. */
  requires: string[];
  /** What later steps can read at `steps.<id>.<key>`. */
  outputs: StepOutputMeta[];
  category?: StepMeta['category'];
  /** Sends an email (PHP `EmailStep`): `customer` has an unsubscribe link, `staff` none. */
  email?: CatalogStepMeta['email'];
  defaults: () => Config;
  normalize: (config: Config) => Config;
  /** With the workflow's trigger (its step id), also the checks that need it. */
  firstIssue: (config: Config, trigger?: string | null) => { message: string; field?: string } | null;
  firstError: (config: Config, trigger?: string | null) => string | null;
  /** Well-formed merge tags in the fields that accept them. */
  tags: (config: Config) => { field: string; path: string }[];
  /** Whether the step's form draws this field, so an issue about it can show under it. */
  drawsField: (key: string) => boolean;
  /** Data items the step reads besides its merge tags, `site` excluded. */
  uses: (config: Config) => string[];
  /** Card text, with `SummaryText` marks; `trigger` (its step id) names its facts. */
  summary: (config: Config, trigger?: string | null) => string;
  icon?: LucideIcon;
  /** Omitted when the step has nothing to set. */
  Form?: ComponentType<StepFormProps<Config>>;
  Actions?: ComponentType;
  /** The plugin's name when the step's plugin isn't active: drawn greyed, read-only, kept on save. */
  inactive?: string;
}
