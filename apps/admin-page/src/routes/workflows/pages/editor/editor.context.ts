import { createContext, useContext } from 'react';

/** Editor state that canvas nodes need but that isn't part of the workflow itself. */
export interface EditorState {
  /** Step whose panel is open; highlighted like a selection. */
  openNodeId: string | null;
  /** Opens a step's panel; `picking` shows the block picker even when a block is chosen (swap). */
  openPanel: (nodeId: string, picking?: boolean) => void;
  /** Steps with a validation issue, marked with a red "Error" header once Turn on or Apply has failed on issues; empty before. */
  issueNodeIds: ReadonlySet<string>;
  /** Asks to delete these steps (one confirm); anything below them stays on the canvas, unlinked. */
  requestDelete: (nodeIds: string[]) => void;
}

export const EditorContext = createContext<EditorState | null>(null);

export function useEditorContext() {
  const state = useContext(EditorContext);
  if (!state) throw new Error('useEditorContext must be used inside EditorContext');
  return state;
}
