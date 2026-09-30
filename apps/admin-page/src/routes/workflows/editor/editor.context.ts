import { createContext, useContext } from 'react';

/**
 * How steps with issues are marked. `review` (blue) until Turn on or Apply fails on them, then
 * `error` (red) for the rest of the editing session.
 */
export type IssueMode = 'review' | 'error';

/** Editor state that canvas nodes need but that isn't part of the workflow itself. */
export interface EditorState {
  /** Step whose settings sheet is open; highlighted like a selection. */
  openNodeId: string | null;
  /** Steps with a validation issue; they get a "Review" or "Error" header, per `issueMode`. */
  issueNodeIds: ReadonlySet<string>;
  issueMode: IssueMode;
  /** Deletes just these steps; anything below them stays on the canvas, unlinked. */
  requestDelete: (nodeIds: string[]) => void;
}

export const EditorContext = createContext<EditorState | null>(null);

export function useEditorContext() {
  const state = useContext(EditorContext);
  if (!state) throw new Error('useEditorContext must be used inside EditorContext');
  return state;
}

/** Router state for `/workflows/:id/edit`. `showIssues`: Turn on failed elsewhere; open in error mode. */
export interface EditorNavState {
  showIssues?: boolean;
}
