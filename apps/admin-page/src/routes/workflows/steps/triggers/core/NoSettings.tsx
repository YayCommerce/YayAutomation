import { Info } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Hint } from './CartTriggerNotes';

/** What to know about a step: when a trigger starts, what an action skips. One line each. */
export function Hints({ hints }: { hints: string[] }) {
  return (
    <div className="flex flex-col gap-3">
      {hints.map((hint) => (
        <Hint key={hint} icon={<Info aria-hidden />}>
          {hint}
        </Hint>
      ))}
    </div>
  );
}

/** A trigger with no fields: says so, then its hints. */
export function NoSettings({ hints }: { hints: string[] }) {
  return (
    <div className="flex flex-col gap-3">
      <p className="text-muted-foreground text-sm">{__('No settings needed.', 'yaymatik')}</p>
      <Hints hints={hints} />
    </div>
  );
}
