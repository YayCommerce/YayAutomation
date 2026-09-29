import type { ComponentType } from 'react';
import type { BlockId, WorkflowNodeKind, WorkflowTemplateApp } from '@yay-automation/types';
import type * as v from 'valibot';

export type Config = Record<string, unknown>;

export interface BlockFormProps<TConfig> {
  /** Initial values. The form owns its state after mount. */
  config: TConfig;
  /** Called on every change, so the workflow draft always holds the latest values. */
  onChange: (config: TConfig) => void;
}

/** What a block folder passes to `defineBlock()`. */
export interface BlockSpec<TConfig extends Config> {
  id: BlockId;
  kind: WorkflowNodeKind;
  /** Integration the block belongs to. Not shown yet; kept for filtering once there are many blocks. */
  app: WorkflowTemplateApp;
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
  app: WorkflowTemplateApp;
  label: string;
  description: string;
  defaults: () => Config;
  normalize: (config: Config) => Config;
  /** The first validation message, or null when the config is valid. */
  firstError: (config: Config) => string | null;
  summary: (config: Config) => string;
  Form: ComponentType<BlockFormProps<Config>>;
}
