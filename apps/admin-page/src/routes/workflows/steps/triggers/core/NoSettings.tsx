import { Info } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Hint } from './CartTriggerNotes';

/** A trigger with no fields: says so, then what to know about when it starts. */
export function NoSettings({ hints }: { hints: string[] }) {
  return (
    <div className="flex flex-col gap-3">
      <p className="text-muted-foreground text-sm">{__('No settings needed.', 'yaymatik')}</p>
      {hints.map((hint) => (
        <Hint key={hint} icon={<Info aria-hidden />}>
          {hint}
        </Hint>
      ))}
    </div>
  );
}
