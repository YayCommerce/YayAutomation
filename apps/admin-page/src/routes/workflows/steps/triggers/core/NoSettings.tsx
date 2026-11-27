import type { ReactNode } from 'react';
import { Info } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Hint } from './CartTriggerNotes';

/** What to know about a step: when a trigger starts, what an action skips. One line each, with Info unless it brings its own icon. */
export function Hints({ hints }: { hints: (string | { text: string; icon: ReactNode })[] }) {
  return (
    <div className="flex flex-col gap-3">
      {hints.map((hint) => {
        const { text, icon } = typeof hint === 'string' ? { text: hint, icon: <Info aria-hidden /> } : hint;
        return (
          <Hint key={text} icon={icon}>
            {text}
          </Hint>
        );
      })}
    </div>
  );
}

/** A trigger with no fields: says so, then its hints. */
export function NoSettings({ hints }: { hints: string[] }) {
  return (
    <div className="flex flex-col gap-3">
      <p className="text-muted-foreground text-sm">
        {__('No settings needed.', 'yaymatik-automation-for-woocommerce')}
      </p>
      <Hints hints={hints} />
    </div>
  );
}
