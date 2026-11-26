import { CircleAlert, CircleCheck, Loader2 } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { formatDateTime } from '@/lib/helpers/date.helper';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import type { AutosaveStatus } from './useAutosave';

/** Autosave pill: "Saving…", "Saved · Last changed on …", or "Couldn't save" with Retry. */
export function SaveStatus({
  status,
  error,
  lastChanged,
  onRetry,
  className,
}: {
  status: AutosaveStatus;
  error: Error | null;
  /** ISO date of the last saved change. */
  lastChanged: string;
  onRetry: () => void;
  className?: string;
}) {
  return (
    <div
      role="status"
      className={cn(
        'bg-card text-muted-foreground flex items-center gap-2 rounded-md border px-3 py-1.5 text-xs shadow-sm',
        className,
      )}
    >
      {status === 'error' ? (
        <>
          <CircleAlert className="text-destructive size-3.5" aria-hidden />
          <span className="text-destructive" title={error?.message}>
            {__('Couldn’t save', 'yaymatik-automation-for-woocommerce')}
          </span>
          <Button variant="link" size="sm" className="h-auto p-0 text-xs" onClick={onRetry}>
            {__('Retry', 'yaymatik-automation-for-woocommerce')}
          </Button>
        </>
      ) : status === 'saved' ? (
        <>
          <CircleCheck className="size-3.5" aria-hidden />
          <span className="text-foreground">{__('Saved', 'yaymatik-automation-for-woocommerce')}</span>
          <span>
            {sprintf(
              /* translators: %s: date and time */
              __('Last changed on %s', 'yaymatik-automation-for-woocommerce'),
              formatDateTime(lastChanged),
            )}
          </span>
        </>
      ) : (
        <>
          <Loader2 className="size-3.5 animate-spin" aria-hidden />
          {__('Saving…', 'yaymatik-automation-for-woocommerce')}
        </>
      )}
    </div>
  );
}
