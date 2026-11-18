import type { StepId, WorkflowNodeKind, WorkflowPlugin, WorkflowTemplate } from '@yaymatik/types';

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
  /** What a blank required field says, with `{{ step }}` for the step's label, e.g. "{{ step }} needs a note.". */
  requiredMessage?: string;
  /** Accepts merge tags. */
  variables?: true;
  placeholder?: string;
  /** text: suggestions the app loads; any other text can be typed. */
  suggest?: 'customer-meta-keys';
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

/** A step whose plugin is off: enough to draw it and keep the workflow (docs/extension-api.md §10). */
export interface InactiveStepMeta {
  module: string;
  /** The plugin's name, e.g. "YayMail". */
  plugin: string;
  kind: WorkflowNodeKind;
  label: string;
  outputs: { key: string; label: string }[];
  /** A trigger's fact keys, so merge tags reading them stay valid while its plugin is off. */
  facts: string[];
}

/** A step's kind and data needs; `CatalogStepMeta` adds the rest of its contract. */
export interface StepMeta {
  kind: WorkflowNodeKind;
  /** Triggers and actions. */
  plugin?: WorkflowPlugin;
  /** Data items the step adds: a trigger's for every run, an action's for the steps below it. */
  provides?: string[];
  /** Picker section: a key of `automation.categories`. */
  category?: string;
  /** Actions: data items needed whatever the config. */
  requires?: string[];
  /** Triggers: values true only at event time, keyed `trigger.<key>`, shaped like data fields. */
  facts?: DataItemMeta['fields'];
}

/** PHP `Core\Definition\Catalog::step()`: a step PHP has built, with its full contract. */
export interface CatalogStepMeta extends StepMeta {
  plugin: WorkflowPlugin;
  label: string;
  description: string;
  /** A name from PHP `StepIcons::NAMES` (lucide); '' for another plugin's step that names none (its logo is drawn). */
  icon: string;
  /** Card text with `%field key%` placeholders. */
  summary: string;
  onError: 'stop' | 'continue';
  /** In form order. */
  fields: StepFieldMeta[];
  outputs: StepOutputMeta[];
  /** Sends an email (PHP `EmailStep`): Preview and Send test; `customer` has an unsubscribe link. */
  email?: 'customer' | 'staff';
  /** Another plugin's action: its run-detail line with `{{ output.<key> }}` / `{{ config.<field> }}` placeholders; '' = the summary. */
  result?: string;
  /** Another plugin's iframe editor: the config field its `save` fills, and the button's label. */
  editor?: { field: string; button: string };
}

export type DataFieldType = 'number' | 'string' | 'enum' | 'date' | 'boolean' | 'list' | 'meta';

/** PHP `DataRegistry::to_client()`: one data item and its fields. */
export interface DataItemMeta {
  id: string;
  label: string;
  /** One line for the "Insert variable" list. */
  description: string;
  /** A step icon name for its "Insert variable" tile. */
  icon: string;
  fields: {
    /** Full key, e.g. `order.total`. */
    key: string;
    label: string;
    /** Condition type: picks the operators. */
    type: DataFieldType;
    /** e.g. `money`, `list<entity<order_item>>`. */
    valueType: string;
    /** False: merge tag only, not offered in conditions. */
    conditional: boolean;
    options?: { value: string; label: string }[];
    /** Shown under a rule on this field. */
    help?: string;
    /** A list searched through REST; its values are never sent here. */
    search?: 'product' | 'category' | 'coupon';
    /** False: not offered by "Insert variable" and not a known merge tag. */
    mergeTag?: false;
    /** A ready-made email block (e.g. a product grid), rendered on the server; inserted on its own line. */
    block?: boolean;
  }[];
  /** Links to one other item: `order.customer` → `order.customer.first_name`. */
  relations: { key: string; label: string; item: string }[];
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
        /** Where this user's test emails go: the address they saved, else their profile email. */
        testEmail: string;
      };
      wc: {
        active: boolean;
        currencySymbol: string;
        /** What a Free shipping method needs to accept a coupon; '' when none accepts one. */
        freeShippingCoupon: 'coupon' | 'coupon_and_minimum' | '';
        cartTracking: boolean;
        /** Minutes without activity before a cart counts as abandoned. */
        abandonMinutes: number;
        /** WooCommerce's Enable product reviews. Changes in another tab, so it can be stale until a reload. */
        reviewsEnabled: boolean;
      };
      automation: {
        plugins: PluginMeta[];
        /** By step id; another plugin's ids too. */
        steps: Partial<Record<StepId, CatalogStepMeta>>;
        /** PHP `Catalog::categories()`: picker section labels by id, core's and other plugins'. */
        categories: Record<string, string>;
        /** PHP `Catalog::inactive_steps()`: steps of other plugins that aren't active now, by id. */
        inactiveSteps: Record<string, InactiveStepMeta>;
        /** PHP `ProCatalog::missing()`: what a workflow may use that this site has without YayMatik Pro; all empty with it. */
        proMissing: { steps: string[]; fields: string[]; features: string[] };
        data: DataItemMeta[];
        /** Operator ids per field type. */
        operators: Record<DataFieldType, string[]>;
        /** PHP `Operators::labels()`: how each operator reads ("is greater than"), by id. */
        operatorLabels: Record<string, string>;
        /** Data items every workflow has, whatever the trigger. */
        always: string[];
        /** PHP `Core\Definition\Messages::all()`. Same as `model/messages.ts`. */
        messages: Record<string, string>;
        /** PHP `Catalog::templates()`: active plugins' templates, built and checked on the server. */
        templates: WorkflowTemplate[];
        /** WooCommerce's sender. `fromName` is plain text, may be blank. */
        email: { fromLine: string; fromName: string; fromAddress: string };
      };
    };
  }
}

export {};
