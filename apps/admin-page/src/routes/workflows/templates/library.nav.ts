/**
 * History-state markers for the template library. When we know an entry was pushed by the
 * library flow, "close" and "back" pop it with `navigate(-1)` instead of stacking a new one,
 * so the browser Back button keeps matching what the user sees.
 */

export const LIBRARY_PATH = '/workflows?templates=list';

export interface LibraryNavState {
  /** Set on the `?templates=list` entry pushed by opening the library. */
  libraryOpened?: boolean;
  /** Set on the preview entry pushed by clicking a template card. */
  fromLibrary?: boolean;
}

export function readLibraryNavState(state: unknown): LibraryNavState {
  return state && typeof state === 'object' ? (state as LibraryNavState) : {};
}
