import { createContext, useContext } from 'react';

import { alwaysAvailable } from '../data';

/** What "Add a variable" offers in the open step: the data it can read, and the trigger's name for the list. */
export interface VariableScope {
  /** Data items the step can read: the trigger's, those added by actions above it, and the always-available ones. */
  available: string[];
  /** The workflow's trigger, shown first in the list; null until one is chosen. */
  trigger: string | null;
}

export const VariableScopeContext = createContext<VariableScope>({ available: alwaysAvailable, trigger: null });

export const useVariableScope = () => useContext(VariableScopeContext);
