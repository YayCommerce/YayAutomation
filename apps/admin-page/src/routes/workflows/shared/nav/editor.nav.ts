/**
 * Router state for `/workflows/:id/edit`, set by pages that send the user to the editor.
 * `showIssues`: Activate was refused elsewhere (Overview, list); open in error mode.
 */
export interface EditorNavState {
  showIssues?: boolean;
}
