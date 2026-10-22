import type { ComponentType } from 'react';
import type { StepId, WorkflowNodeKind, WorkflowPlugin } from '@yaymatik/types';
import type { LucideIcon } from 'lucide-react';
import type * as v from 'valibot';

import type { StepMeta, StepOutputMeta } from '@/types/localize';
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
  /** Pending steps only. */
  label?: string;
  /** Pending steps only. */
  description?: string;
  /** Pending steps only: the one source of a valid config. */
  schema?: v.GenericSchema<TConfig>;
  /** A built step passes these only for sample text PHP doesn't keep. */
  defaults?: () => TConfig;
  /** Mirrors the PHP step's `sanitize()`. */
  normalize?: (config: Config) => TConfig;
  /** Data items the step reads besides its merge tags. Mirrors the PHP step's `uses()`. */
  uses?: (config: TConfig) => string[];
  /** Pending steps only. Gets incomplete config too, so show placeholders for gaps. */
  summary?: (config: TConfig) => string;
  /** Catalog summary placeholders a field can't write on its own (e.g. a condition's `%rules%`). */
  summaryValues?: SummaryValues<TConfig>;
  /** Pending steps only. */
  icon?: LucideIcon;
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
  /** Shown but can't be picked (see `pending.ts`). */
  comingSoon: boolean;
  defaults: () => Config;
  normalize: (config: Config) => Config;
  firstIssue: (config: Config) => { message: string; field?: string } | null;
  firstError: (config: Config) => string | null;
  /** Well-formed merge tags in the fields that accept them (all text while pending). */
  tags: (config: Config) => { field: string; path: string }[];
  /** Whether the step's form draws this field, so an issue about it can show under it. */
  drawsField: (key: string) => boolean;
  /** Data items the step reads besides its merge tags, `site` excluded. */
  uses: (config: Config) => string[];
  /** Card text, with `SummaryText` marks. */
  summary: (config: Config) => string;
  icon?: LucideIcon;
  /** Omitted when the step has nothing to set. */
  Form?: ComponentType<StepFormProps<Config>>;
  Actions?: ComponentType;
}
