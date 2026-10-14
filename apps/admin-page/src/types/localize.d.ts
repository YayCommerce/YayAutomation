import type { StepId, WorkflowNodeKind, WorkflowPlugin } from '@yay-automation/types';

/** PHP `Workflow\Extend\Plugins::to_client()`. */
export interface PluginMeta {
  id: WorkflowPlugin;
  label: string;
  description: string;
  active: boolean;
  /** Own SVG, cleaned with `wp_kses()`; draws in `currentColor`. */
  logo: string;
}

/** One entry of an option list, in order. */
export interface OptionMeta {
  value: string;
  label: string;
}

/** PHP `Steps\Core\Field` widgets. `rules` is the Condition rules builder (core steps only). */
export type FieldWidget =
  'text' | 'textarea' | 'emails' | 'select' | 'multiselect' | 'toggle' | 'number' | 'number_unit' | 'notice' | 'rules';

/** PHP `Field::to_array()`: one setting of a step. Optional keys are sent only when set. */
export interface StepFieldMeta {
  /** Config key. */
  key: string;
  type: FieldWidget;
  label: string;
  required?: true;
  /** What a blank required field says, with `%s` for the step's label, e.g. "%s needs a note.". */
  requiredMessage?: string;
  /** Accepts merge tags. */
  variables?: true;
  placeholder?: string;
  help?: string;
  /** Shown only while another field has this value (or one of these values). */
  showIf?: { field: string; value: unknown };
  /** select, multiselect; units for number_unit. */
  options?: OptionMeta[];
  /** The stored value when nothing is set. Absent on notice. */
  default?: unknown;
  min?: number;
  max?: number;
  /** notice: the text shown. */
  text?: string;
}

/** One declared output: later steps read it at `steps.<id>.<key>`. */
export interface StepOutputMeta {
  key: string;
  label: string;
  /** Same text form as a data field's `valueType`, e.g. `enum`, `list<string>`. */
  type: string;
  options?: OptionMeta[];
}

/** What the admin knows about each step: PHP's catalog entry (`CatalogStepMeta`) or a pending stub. */
export interface StepMeta {
  kind: WorkflowNodeKind;
  /** Triggers and actions. */
  plugin?: WorkflowPlugin;
  /** Data items the step adds: a trigger's for every run, an action's for the steps below it. */
  provides?: string[];
  /** Actions: picker section. */
  category?: 'email' | 'order' | 'coupon' | 'flow';
  /** Actions: data items needed whatever the config. */
  requires?: string[];
}

/** PHP `Definition\Catalog::step()`: a step PHP has built, with its full contract. */
export interface CatalogStepMeta extends StepMeta {
  plugin: WorkflowPlugin;
  /** `Extend\Modules` id. */
  module: string;
  label: string;
  description: string;
  /** A name from PHP `StepIcons::NAMES` (lucide). */
  icon: string;
  /** Card text with `%field key%` placeholders. */
  summary: string;
  onError: 'stop' | 'continue';
  /** Settings, in form order. */
  fields: StepFieldMeta[];
  outputs: StepOutputMeta[];
}

export type DataFieldType = 'number' | 'string' | 'enum' | 'date' | 'boolean';

/** PHP `DataRegistry::to_client()`: one data item and its fields. */
export interface DataItemMeta {
  id: string;
  label: string;
  /** One line for the "Insert variable" list. */
  description: string;
  fields: {
    /** Full key, e.g. `order.total`. */
    key: string;
    label: string;
    /** Condition type: picks the operators. */
    type: DataFieldType;
    /** Full value type, e.g. `money`, `list<entity<order_item>>`. Always sent by PHP; absent on fields the app defines itself. */
    valueType?: string;
    /** False: merge tag only, not offered in conditions. */
    conditional: boolean;
    /** Enum fields. */
    options?: { value: string; label: string }[];
  }[];
  /** Links to one other item (`order.customer` → `customer`): its fields read as `order.customer.first_name`. Sent by PHP; absent on items the app defines itself. */
  relations?: { key: string; label: string; item: string }[];
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
        /** Steps PHP has built; the admin may list more (see `steps/core/pending.ts`). */
        steps: Partial<Record<StepId, CatalogStepMeta>>;
        data: DataItemMeta[];
        /** Operator ids per field type. Labels live in the admin app. */
        operators: Record<DataFieldType, string[]>;
        /** Data items every workflow has, whatever the trigger. */
        always: string[];
        /** PHP `Definition\Messages::all()`: messages both sides show, by key. Same as `model/messages.ts`. */
        messages: Record<string, string>;
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
