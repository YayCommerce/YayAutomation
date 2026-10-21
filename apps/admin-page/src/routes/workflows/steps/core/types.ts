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
   * Pending steps only: the one source of truth for a valid config (field errors in the form and
   * the workflow's `invalid_config` issue). A built step's schema comes from its catalog fields.
   */
  schema?: v.GenericSchema<TConfig>;
  /**
   * A new step's config. A built step's come from its catalog fields; pass them only for sample
   * text PHP doesn't keep.
   */
  defaults?: () => TConfig;
  /**
   * Brings stored config into the current shape: fills missing keys. Mirrors the PHP step's
   * `sanitize()`. Defaults to merging over `defaults()`.
   */
  normalize?: (config: Config) => TConfig;
  /**
   * Data items the step reads with this config, besides its merge tags (the workflow checks those
   * through `tags`). Mirrors the PHP step's `uses()`. Defaults to the step's `requires`.
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
  /** A custom form. Without one, a built step's form is drawn from its catalog fields (`StepForm`). */
  Form?: ComponentType<StepFormProps<TConfig>>;
  /** Drawn under the form with its latest values, e.g. Send email's unsubscribe note. */
  Footer?: ComponentType<{ config: TConfig }>;
  /** Drawn in the panel header under the title, e.g. Send email's Preview and Send test. */
  Actions?: ComponentType;
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
  /** The first validation problem, with the field it's about when it has one; null when the config is valid. */
  firstIssue: (config: Config) => { message: string; field?: string } | null;
  /** `firstIssue()`'s message. */
  firstError: (config: Config) => string | null;
  /** The well-formed merge tags in the fields that accept them (all text while pending), each with its field. */
  tags: (config: Config) => { field: string; path: string }[];
  /** Whether the step's form draws this field, so an issue about it can show under it. */
  drawsField: (key: string) => boolean;
  /** Data items the step reads besides its merge tags, `site` excluded. */
  uses: (config: Config) => string[];
  /** Card text, with `SummaryText` marks. */
  summary: (config: Config) => string;
  /** The step's own icon; without one the card shows its kind's. */
  icon?: LucideIcon;
  /** Omitted when the step has nothing to set. */
  Form?: ComponentType<StepFormProps<Config>>;
  /** Panel header actions under the title (`StepSpec.Actions`). */
  Actions?: ComponentType;
}
