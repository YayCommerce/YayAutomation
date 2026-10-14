import type { ComponentType } from 'react';
import type { StepId, WorkflowNodeKind, WorkflowPlugin } from '@yay-automation/types';
import type { LucideIcon } from 'lucide-react';
import type * as v from 'valibot';

import type { StepMeta, StepOutputMeta } from '@/types/localize';
import type { SummaryValues } from './summary';

export type Config = Record<string, unknown>;

export interface StepFormProps<TConfig> {
  /** Initial values. The form owns its state after mount. */
  config: TConfig;
  /** Called on every change, so the workflow draft always holds the latest values. */
  onChange: (config: TConfig) => void;
}

/** What a step folder passes to `defineStep()`. Plugin and data facts come from PHP (`StepMeta`). */
export interface StepSpec<TConfig extends Config> {
  id: StepId;
  kind: WorkflowNodeKind;
  /** Pending steps only: a built step's label, description, icon and summary come from PHP's catalog. */
  label?: string;
  description?: string;
  /**
   * The one source of truth for a valid config: field errors in the form and the workflow's
   * `invalid_config` issue. Must accept exactly what the PHP step's `validate()` accepts.
   */
  schema: v.GenericSchema<TConfig>;
  defaults: () => TConfig;
  /**
   * Brings stored config into the current shape: fills missing keys. Mirrors the PHP step's
   * `sanitize()`. Defaults to merging over `defaults()`.
   */
  normalize?: (config: Config) => TConfig;
  /**
   * Data items the step reads with this config, besides its merge tags (the workflow checks those
   * through `tagPaths`). Mirrors the PHP step's `uses()`. Defaults to the step's `requires`.
   */
  uses?: (config: TConfig) => string[];
  /**
   * Pending steps only: plain-language line for the node card. Gets incomplete config too, so show
   * placeholders for gaps. Ignored once PHP's catalog has the step: what its template can't write
   * from the fields alone (units, plurals) moves to `summaryValues` then.
   */
  summary?: (config: TConfig) => string;
  /** Catalog summary placeholders a field can't write on its own (e.g. a condition's `%rules%`). */
  summaryValues?: SummaryValues<TConfig>;
  /** Pending steps only: replaces the step kind's icon on the card and in the panel header. */
  icon?: LucideIcon;
  /** Omitted when the step has nothing to configure. */
  Form?: ComponentType<StepFormProps<TConfig>>;
}

/** A step with its config type erased, so one registry can hold them all. Every method normalizes first. */
export interface Step {
  id: StepId;
  kind: WorkflowNodeKind;
  label: string;
  description: string;
  /** Triggers and actions. */
  plugin?: WorkflowPlugin;
  /** Data items the step adds: a trigger's for every run, an action's for the steps below it. */
  provides: string[];
  /** Actions: data items needed whatever the config; the picker dims the action without them. */
  requires: string[];
  /** What later steps can read at `steps.<id>.<key>`. From PHP's catalog; none while pending. */
  outputs: StepOutputMeta[];
  /** Actions: picker section. */
  category?: StepMeta['category'];
  /** Its PHP side isn't built yet: shown but can't be picked (see `pending.ts`). */
  comingSoon: boolean;
  defaults: () => Config;
  normalize: (config: Config) => Config;
  /** The first validation message, or null when the config is valid. */
  firstError: (config: Config) => string | null;
  /** Paths of the well-formed merge tags in the fields that accept them (all text while pending). */
  tagPaths: (config: Config) => string[];
  /** Data items the step reads besides its merge tags, `site` excluded. */
  uses: (config: Config) => string[];
  /** Card text, with `SummaryText` marks. */
  summary: (config: Config) => string;
  /** The step's own icon; without one the card shows its kind's. */
  icon?: LucideIcon;
  Form?: ComponentType<StepFormProps<Config>>;
}
