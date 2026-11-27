import { __, sprintf } from '@wordpress/i18n';

import { shortDayTime } from '@/lib/helpers/date.helper';
import { cn } from '@/lib/utils';
import { HelpTip } from '@/components/ui/tooltip';

/** The status a run step's `output.delivery` shows: what a mail plugin reported last through `yaymatik_email_status`. */
export interface EmailDelivery {
  status: keyof typeof labels;
  at: string;
  /** Why it bounced or failed, as the mail plugin says it. */
  reason?: string;
}

const labels = {
  delivered: __('Delivered', 'yaymatik'),
  opened: __('Opened', 'yaymatik'),
  clicked: __('Clicked', 'yaymatik'),
  bounced: __('Bounced', 'yaymatik'),
  failed: __('Failed', 'yaymatik'),
};

/**
 * Sent gives no line, nor failed on a failed step: its "Sent to …" or its error already says it. Failed on a
 * completed step is a mail plugin's later report.
 */
export function shownDelivery(value: unknown, stepStatus: string): EmailDelivery | undefined {
  if (!value || typeof value !== 'object') return undefined;
  const { status, at, reason } = value as Record<string, unknown>;
  if (typeof status !== 'string' || !Object.hasOwn(labels, status) || typeof at !== 'string') return undefined;
  if (status === 'failed' && stepStatus !== 'completed') return undefined;
  return { status: status as EmailDelivery['status'], at, reason: typeof reason === 'string' ? reason : undefined };
}

/** "● Delivered · Nov 27 at 10:42 AM" under a step's "Sent to …"; bounced or failed in red, its reason on hover. */
export function DeliveryStatus({ delivery }: { delivery: EmailDelivery }) {
  const bounced = delivery.status === 'bounced' || delivery.status === 'failed';
  return (
    <p
      className={cn(
        'm-0 mt-0.5 flex items-center gap-1.5 text-xs',
        bounced ? 'text-destructive' : 'text-muted-foreground-600',
      )}
    >
      <span aria-hidden="true" className={cn('size-1.5 rounded-full', bounced ? 'bg-destructive' : 'bg-success')} />
      {sprintf(
        /* translators: 1: delivery status, e.g. "Delivered"; 2: date and time, e.g. "Oct 9 at 3:00 PM" */
        __('%1$s · %2$s', 'yaymatik'),
        labels[delivery.status],
        shortDayTime(delivery.at),
      )}
      {bounced && delivery.reason && <HelpTip content={delivery.reason} />}
    </p>
  );
}
