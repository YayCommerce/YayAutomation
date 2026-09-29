import { createContext, useContext } from 'react';

/** Editor state that canvas nodes need but that isn't part of the workflow itself. */
export interface EditorState {
  /** Step whose settings sheet is open; highlighted like a selection. */
  openNodeId: string | null;
  /** Steps with a validation issue; they get the red "Error" header. */
  errorNodeIds: ReadonlySet<string>;
  /** Deletes just these steps; anything below them stays on the canvas, unlinked. */
  requestDelete: (nodeIds: string[]) => void;
}

export const EditorContext = createContext<EditorState | null>(null);

export function useEditorContext() {
  const state = useContext(EditorContext);
  if (!state) throw new Error('useEditorContext must be used inside EditorContext');
  return state;
}
