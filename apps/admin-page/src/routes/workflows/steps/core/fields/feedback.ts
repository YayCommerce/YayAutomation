import { createContext, useContext } from 'react';

/**
 * How a block form shows its errors, set by the editor per step:
 * - `none`: quiet, the step is being filled in for the first time;
 * - `review`: blue tint + message, the step was left unfinished;
 * - `error`: red, a Turn on / Apply failed.
 */
export type Feedback = 'none' | 'review' | 'error';

export const FeedbackContext = createContext<Feedback>('error');

export const useFeedback = () => useContext(FeedbackContext);

/**
 * For a control's `aria-invalid`: outlines turn red only after a failed Turn on / Apply. Review mode
 * tints through `ConfigField` instead, and a step being filled in stays quiet.
 */
export function useMarkInvalid() {
  const feedback = useFeedback();
  return (meta: { isValid: boolean }) => feedback === 'error' && !meta.isValid;
}

/** Tint and message color per mode, shared by every field and the criteria boxes. */
export const feedbackStyles = {
  review: { tint: 'border-primary/40 bg-primary/5', text: 'text-primary' },
  error: { tint: 'border-destructive bg-destructive/5', text: 'text-destructive' },
} as const;
