import { createContext, useContext } from 'react';

/** The open step's iframe editor, from the panel: its URL for the unsaved step, and writing its field. Null outside. */
export interface EditorTools {
  open: () => Promise<{ url: string; sample: { orderId: number | null; userId: number | null } }>;
  save: (field: string, value: string | number) => void;
}

export const EditorToolsContext = createContext<EditorTools | null>(null);

export const useEditorTools = () => useContext(EditorToolsContext);
