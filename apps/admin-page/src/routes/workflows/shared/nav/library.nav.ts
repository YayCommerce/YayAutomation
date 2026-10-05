/**
 * History-state marker for the template library. When we know the preview entry was pushed by
 * clicking a card, "close" and "back" pop it with `navigate(-1)` instead of stacking a new one,
 * so the browser Back button keeps matching what the user sees.
 */

export const LIBRARY_PATH = '/workflows?templates=list';

export interface LibraryNavState {
  /** Set on the preview entry pushed by clicking a template card. */
  fromLibrary?: boolean;
}

export function readLibraryNavState(state: unknown): LibraryNavState {
  return state && typeof state === 'object' ? (state as LibraryNavState) : {};
}
