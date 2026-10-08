import { createContext, useContext } from 'react';

import type { IssueMode } from '../../shared/canvas/NodeCardView';

/** Editor state that canvas nodes need but that isn't part of the workflow itself. */
export interface EditorState {
  /** Step whose panel is open; highlighted like a selection. */
  openNodeId: string | null;
  /** Opens a step's panel; `picking` shows the step picker even when a step is chosen (swap). */
  openPanel: (nodeId: string, picking?: boolean) => void;
  /** Steps showing an issue: blue "Review" (left unfinished) or red "Error" (after a failed Turn on / Apply). */
  issueModes: ReadonlyMap<string, IssueMode>;
  /** Asks to delete these steps (one confirm); anything below them stays on the canvas, unlinked. */
  requestDelete: (nodeIds: string[]) => void;
}

export const EditorContext = createContext<EditorState | null>(null);

export function useEditorContext() {
  const state = useContext(EditorContext);
  if (!state) throw new Error('useEditorContext must be used inside EditorContext');
  return state;
}
