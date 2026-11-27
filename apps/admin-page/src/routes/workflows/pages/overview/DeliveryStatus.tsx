import { __, sprintf } from '@wordpress/i18n';

import { shortDayTime } from '@/lib/helpers/date.helper';
import { cn } from '@/lib/utils';
import { HelpTip } from '@/components/ui/tooltip';

/** What a mail plugin reported last for the email a step sent, through `yaymatik_email_status`. */
export interface EmailDelivery {
  status: 'delivered' | 'opened' | 'clicked' | 'bounced';
  at: string;
  /** Why it bounced, as the mail plugin says it. */
  reason?: string;
}

const labels: Record<EmailDelivery['status'], string> = {
  delivered: __('Delivered', 'yaymatik'),
  opened: __('Opened', 'yaymatik'),
  clicked: __('Clicked', 'yaymatik'),
  bounced: __('Bounced', 'yaymatik'),
};

/** "● Delivered · Nov 27 at 10:42 AM" under a step's "Sent to …"; bounced in red, its reason on hover. */
export function DeliveryStatus({ delivery }: { delivery: EmailDelivery }) {
  const bounced = delivery.status === 'bounced';
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
