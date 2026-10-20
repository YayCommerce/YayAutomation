import { createContext, useContext } from 'react';
import type { EmailPreview, EmailTestResult, WorkflowIssue } from '@yaymatik/types';

/**
 * Preview and Send test for the open email step, set by the editor: each posts the step's config
 * and the graph as they are in the editor, saved or not, and changes neither. Null outside the editor.
 * A refusal rejects with an Error whose `issues` (see `issuesOf`) list what's wrong with the step.
 */
export interface EmailTools {
  /** The step's config now; a new one each edit. */
  config: Record<string, unknown>;
  preview: () => Promise<EmailPreview>;
  sendTest: () => Promise<EmailTestResult>;
}

export const EmailToolsContext = createContext<EmailTools | null>(null);

export const useEmailTools = () => useContext(EmailToolsContext);

/**
 * What the server found wrong with the step, from a refusal (`ApiError.issues`, which `steps/`
 * can't import); empty for any other error.
 */
export const issuesOf = (error: unknown): WorkflowIssue[] =>
  error instanceof Error && 'issues' in error && Array.isArray(error.issues) ? (error.issues as WorkflowIssue[]) : [];
