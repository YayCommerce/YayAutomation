import type { StepId, WorkflowNodeKind, WorkflowPlugin } from '@yaymatik/types';

/** PHP `Workflow\ModuleRegistry::to_client()`. */
export interface PluginMeta {
  id: WorkflowPlugin;
  label: string;
  description: string;
  active: boolean;
  /** URL of a one-colour SVG the plugin ships; drawn as a mask in the text colour. */
  logo: string;
}

/** One entry of an option list, in order. */
export interface OptionMeta {
  value: string;
  label: string;
}

/** PHP `Core\Steps\Field` widgets. `rules` is the Condition rules builder (core steps only). */
export type FieldWidget =
  | 'text'
  | 'textarea'
  | 'html'
  | 'emails'
  | 'select'
  | 'multiselect'
  | 'toggle'
  | 'number'
  | 'number_unit'
  | 'notice'
  | 'rules';

/** PHP `Field::to_array()`: one setting of a step. Optional keys are sent only when set. */
export interface StepFieldMeta {
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
  /** Absent on notice. */
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
  /** A ready-made email block (e.g. a coupon box), rendered on the server; inserted on its own line. */
  block?: boolean;
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

/** PHP `Core\Definition\Catalog::step()`: a step PHP has built, with its full contract. */
export interface CatalogStepMeta extends StepMeta {
  plugin: WorkflowPlugin;
  label: string;
  description: string;
  /** A name from PHP `StepIcons::NAMES` (lucide). */
  icon: string;
  /** Card text with `%field key%` placeholders. */
  summary: string;
  onError: 'stop' | 'continue';
  /** In form order. */
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
  /** A step icon name for its "Insert variable" tile. Absent on items the app defines itself. */
  icon?: string;
  fields: {
    /** Full key, e.g. `order.total`. */
    key: string;
    label: string;
    /** Condition type: picks the operators. */
    type: DataFieldType;
    /** e.g. `money`, `list<entity<order_item>>`. Absent on fields the app defines itself. */
    valueType?: string;
    /** False: merge tag only, not offered in conditions. */
    conditional: boolean;
    options?: { value: string; label: string }[];
  }[];
  /** Links to one other item: `order.customer` → `order.customer.first_name`. Absent on items the app defines itself. */
  relations?: { key: string; label: string; item: string }[];
}

declare global {
  interface Window {
    yayMatikMeta: {
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
        /** The logged-in user's: where Send test emails go. */
        userEmail: string;
      };
      wc: {
        active: boolean;
        currencySymbol: string;
        /** What a Free shipping method needs to accept a coupon; '' when none accepts one. */
        freeShippingCoupon: 'coupon' | 'coupon_and_minimum' | '';
      };
      automation: {
        plugins: PluginMeta[];
        /** Steps PHP has built; the admin may list more (see `steps/core/pending.ts`). */
        steps: Partial<Record<StepId, CatalogStepMeta>>;
        data: DataItemMeta[];
        /** Operator ids per field type. */
        operators: Record<DataFieldType, string[]>;
        /** PHP `Operators::labels()`: how each operator reads ("is greater than"), by id. */
        operatorLabels: Record<string, string>;
        /** Data items every workflow has, whatever the trigger. */
        always: string[];
        /** PHP `Core\Definition\Messages::all()`. Same as `model/messages.ts`. */
        messages: Record<string, string>;
        /** WooCommerce's sender. `fromName` is plain text, may be blank. */
        email: { fromLine: string; fromName: string; fromAddress: string };
      };
      docs: {
        workflowRuns: string;
      };
    };
  }
}

export {};
