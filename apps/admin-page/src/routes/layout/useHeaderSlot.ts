import { useEffect, useState } from 'react';

/** Layout's `#yay-header-actions`, once Layout is in the DOM (it isn't during the first render). */
export function useHeaderSlot() {
  const [slot, setSlot] = useState<HTMLElement | null>(null);
  useEffect(() => setSlot(document.getElementById('yay-header-actions')), []);
  return slot;
}
