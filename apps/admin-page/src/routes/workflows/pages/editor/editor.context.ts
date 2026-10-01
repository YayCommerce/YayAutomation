import { createContext, useContext } from 'react';

import type { IssueMode } from '../../shared/canvas/types';

/** Editor state that canvas nodes need but that isn't part of the workflow itself. */
export interface EditorState {
  /** Step whose settings sheet is open; highlighted like a selection. */
  openNodeId: string | null;
  /** Steps with a validation issue; they get a "Review" or "Error" header, per `issueMode`. */
  issueNodeIds: ReadonlySet<string>;
  /** `review` until Turn on or Apply fails on issues, then `error` for the rest of the editing session. */
  issueMode: IssueMode;
  /** Asks to delete these steps (one confirm); anything below them stays on the canvas, unlinked. */
  requestDelete: (nodeIds: string[]) => void;
}

export const EditorContext = createContext<EditorState | null>(null);

export function useEditorContext() {
  const state = useContext(EditorContext);
  if (!state) throw new Error('useEditorContext must be used inside EditorContext');
  return state;
}
