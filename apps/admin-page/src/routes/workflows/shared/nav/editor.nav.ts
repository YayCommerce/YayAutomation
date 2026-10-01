/**
 * Router state for `/workflows/:id/edit`, set by pages that send the user to the editor.
 * `showIssues`: Turn on failed on the Overview; open in error mode.
 */
export interface EditorNavState {
  showIssues?: boolean;
}
