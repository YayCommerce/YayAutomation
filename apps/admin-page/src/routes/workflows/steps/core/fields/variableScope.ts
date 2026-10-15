import { createContext, useContext } from 'react';
import type { WorkflowNodeKind } from '@yay-automation/types';
import type { LucideIcon } from 'lucide-react';

import { availableVariables, type Variable } from '../variables';

/** A step as "Insert variable" shows it: on a tile like its card. */
export interface ScopeStep {
  label: string;
  kind: WorkflowNodeKind;
  icon?: LucideIcon;
  /** Its card summary, plain: tells two steps of the same kind apart. */
  summary: string;
}

/** What "Insert variable" offers in the open step, and the steps its data comes from. */
export interface VariableScope {
  /** `availableVariables()` for the step. */
  variables: Variable[];
  /** The workflow's trigger and the data items it supplies; null until one is chosen. */
  trigger: (Omit<ScopeStep, 'summary'> & { provides: string[] }) | null;
  /** The steps above, by node id: their outputs are grouped under them. */
  steps: Record<string, ScopeStep>;
  /**
   * The open step is still coming soon: it may use fields PHP doesn't have yet. A built step may not
   * (PHP rejects them on Activate), so they're listed but can't be inserted.
   */
  comingSoonStep: boolean;
}

export const VariableScopeContext = createContext<VariableScope>({
  variables: availableVariables({ nodes: [], edges: [] }, ''),
  trigger: null,
  steps: {},
  comingSoonStep: false,
});

export const useVariableScope = () => useContext(VariableScopeContext);
