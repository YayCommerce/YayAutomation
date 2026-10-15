import { createContext, useContext } from 'react';

/**
 * How a step form shows its errors, set by the editor per step:
 * - `none`: quiet, the step is being filled in for the first time;
 * - `review`: the step was left unfinished (its card says "Needs setup");
 * - `error`: an Activate / Update failed.
 * Forms show the same field errors in `review` and `error`; only the card tells them apart.
 */
export type Feedback = 'none' | 'review' | 'error';

export const FeedbackContext = createContext<Feedback>('error');

export const useFeedback = () => useContext(FeedbackContext);

/** Whether the open form shows its errors. */
export const useShowErrors = () => useFeedback() !== 'none';

/** For a control's `aria-invalid`: red outlines only while the form shows its errors. */
export function useMarkInvalid() {
  const showErrors = useShowErrors();
  return (meta: { isValid: boolean }) => showErrors && !meta.isValid;
}

/** Error tint and text color, shared by the condition rule boxes. */
export const errorStyles = { tint: 'border-destructive bg-destructive/5', text: 'text-destructive' } as const;
