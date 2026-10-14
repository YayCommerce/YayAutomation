import { createContext, useContext } from 'react';

import { availableVariables, type Variable } from '../variables';

/** What "Insert variable" offers in the open step: the variables it can use, and the trigger's name for the list. */
export interface VariableScope {
  /** `availableVariables()` for the step. */
  variables: Variable[];
  /** The workflow's trigger, shown first in the list; null until one is chosen. */
  trigger: string | null;
}

export const VariableScopeContext = createContext<VariableScope>({
  variables: availableVariables({ nodes: [], edges: [] }, ''),
  trigger: null,
});

export const useVariableScope = () => useContext(VariableScopeContext);
