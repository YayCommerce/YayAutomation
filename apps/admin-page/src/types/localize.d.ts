import type { BlockId, WorkflowNodeKind, WorkflowPlugin } from '@yay-automation/types';

/** PHP `Engine\Plugins::to_client()`. */
export interface PluginMeta {
  id: WorkflowPlugin;
  label: string;
  description: string;
  active: boolean;
}

/** PHP `BlockRegistry::to_client()`: what the engine knows about each block. */
export interface BlockMeta {
  kind: WorkflowNodeKind;
  /** Triggers and actions. */
  plugin?: WorkflowPlugin;
  /** Data items the step adds: a trigger's for every run, an action's for the steps below it. */
  provides?: string[];
  /** Actions: picker section. */
  category?: 'email' | 'order' | 'coupon';
  /** Actions: data items needed whatever the config. */
  requires?: string[];
}

export type DataFieldType = 'number' | 'string' | 'enum' | 'date' | 'boolean';

/** PHP `DataRegistry::to_client()`: one data item and its fields. */
export interface DataItemMeta {
  id: string;
  label: string;
  /** One line for the "Add a variable" list. */
  description: string;
  fields: {
    /** Full key, e.g. `order.total`. */
    key: string;
    label: string;
    type: DataFieldType;
    /** False: merge tag only, not offered in conditions. */
    conditional: boolean;
    /** Enum fields. */
    options?: { value: string; label: string }[];
  }[];
}

declare global {
  interface Window {
    yayAutomationMeta: {
      plugin: {
        pluginUrl: string;
        version: string;
      };
      wp: {
        restRoot: string;
        restBase: string;
        restNonce: string;
        siteUrl: string;
        adminUrl: string;
        timezoneString: string;
        gmtOffset: number;
        adminEmail: string;
      };
      wc: {
        active: boolean;
        /** Slugs without the `wc-` prefix. Empty when WooCommerce is inactive. */
        orderStatuses: { value: string; label: string }[];
        currencySymbol: string;
      };
      automation: {
        plugins: PluginMeta[];
        /** Blocks PHP has built; the admin may list more (see `blocks/core/pending.ts`). */
        blocks: Partial<Record<BlockId, BlockMeta>>;
        data: DataItemMeta[];
        /** Operator ids per field type. Labels live in the admin app. */
        operators: Record<DataFieldType, string[]>;
        /** Data items every workflow has, whatever the trigger. */
        always: string[];
        /** WooCommerce email sender, for Send internal email. */
        email: { fromName: string; fromAddress: string; settingsUrl: string };
      };
      docs: {
        /** Filter `yay_automation_workflow_runs_docs_url`. */
        workflowRuns: string;
      };
    };
  }
}

export {};
