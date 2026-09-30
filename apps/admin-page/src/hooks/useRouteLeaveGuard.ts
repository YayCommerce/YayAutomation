import { useEffect, useRef, useState } from 'react';
import { useBlocker } from 'react-router';

/**
 * Hook that guards unsaved form changes.
 * Returns control for showing custom modal.
 *
 * Pass a function to decide at navigation time, e.g. when a save resets the form and navigates
 * before the next render.
 *
 * `beforeLeave` runs first when navigation is blocked (e.g. to finish saving). Resolving true lets
 * the navigation through; false shows the dialog.
 */
export function useRouteLeaveGuard(
  shouldBlock: Parameters<typeof useBlocker>[0],
  beforeLeave?: () => Promise<boolean>,
) {
  const [showDialog, setShowDialog] = useState(false);

  const blocker = useBlocker(shouldBlock);
  // Latest values for the async `beforeLeave` callback, which outlives the render that started it.
  const blockerRef = useRef(blocker);
  const beforeLeaveRef = useRef(beforeLeave);
  useEffect(() => {
    blockerRef.current = blocker;
    beforeLeaveRef.current = beforeLeave;
  });

  useEffect(() => {
    if (blocker.state !== 'blocked') return;
    const check = beforeLeaveRef.current;
    if (!check) {
      setShowDialog(true);
      return;
    }
    void check().then((ok) => {
      // Still blocked on the same attempt (not reset or already proceeded).
      if (blockerRef.current.state !== 'blocked') return;
      if (ok) blockerRef.current.proceed();
      else setShowDialog(true);
    });
  }, [blocker.state]);

  // Confirm Dialog actions
  const confirmLeave = () => {
    setShowDialog(false);
    blocker.proceed?.();
  };

  const cancelLeave = () => {
    setShowDialog(false);
    blocker.reset?.();
  };

  return { showDialog, confirmLeave, cancelLeave };
}
