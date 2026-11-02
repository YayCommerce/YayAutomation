import type { ReactNode } from 'react';
import { CircleCheck, Info, TriangleAlert } from 'lucide-react';
import { Link } from 'react-router';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { SummaryText, value } from '../../core/defineStep';

/** Amber like the "Needs setup" badge, with a link to Settings. */
export function CartTrackingWarning({ message, linkLabel }: { message: string; linkLabel: string }) {
  return (
    <Alert className="bg-warning-soft [&>svg]:text-warning-soft-foreground">
      <TriangleAlert aria-hidden />
      <AlertDescription className="text-foreground">
        <p>
          {message}{' '}
          <Link to="/settings" className="text-foreground underline">
            {linkLabel}
          </Link>
        </p>
      </AlertDescription>
    </Alert>
  );
}

function Hint({ icon, children }: { icon: ReactNode; children: ReactNode }) {
  return (
    <p className="text-muted-foreground flex items-start gap-1.5 text-xs [&>svg]:mt-0.5 [&>svg]:size-3.5 [&>svg]:shrink-0">
      {icon}
      <span>{children}</span>
    </p>
  );
}

/** Both cart triggers have no settings, only what they do. */
export function CartTriggerNotes({ trigger }: { trigger: 'cart' | 'checkout' }) {
  // Read per render: saving Settings changes it.
  const { active, cartTracking, abandonMinutes } = window.yayMatikMeta.wc;
  return (
    <div className="flex flex-col gap-3">
      {active && !cartTracking && (
        <CartTrackingWarning
          message={__('Cart tracking is off, so this trigger never starts.', 'yaymatik')}
          linkLabel={__('Turn on cart tracking in Settings', 'yaymatik')}
        />
      )}
      <p className="text-muted-foreground text-sm">{__('No settings needed.', 'yaymatik')}</p>
      <p className="bg-primary/8 flex items-start gap-2 rounded-lg px-3 py-2.5 text-[13px]">
        <Info className="text-primary mt-0.5 size-4 shrink-0" aria-hidden />
        <span>
          <SummaryText
            text={sprintf(
              /* translators: %s: time, e.g. "60 minutes" */
              __('A cart counts as abandoned after %s without activity.', 'yaymatik'),
              /* translators: %d: number of minutes */
              value(sprintf(_n('%d minute', '%d minutes', abandonMinutes, 'yaymatik'), abandonMinutes)),
            )}
          />{' '}
          <Link to="/settings" className="text-link hover:underline">
            {__('Change it in Settings', 'yaymatik')}
          </Link>
        </span>
      </p>
      <Hint icon={<Info aria-hidden />}>
        {trigger === 'cart'
          ? __('Carts that reached checkout start Checkout abandoned instead, never this trigger.', 'yaymatik')
          : __('Only carts that reached checkout with an email. They never start Cart abandoned.', 'yaymatik')}
      </Hint>
      <Hint icon={<CircleCheck aria-hidden />}>
        {__('Runs stop as soon as the shopper places an order.', 'yaymatik')}
      </Hint>
      <Hint icon={<Info aria-hidden />}>
        {__(
          'Shoppers who unsubscribed, or already got a reminder this week for another cart, are skipped.',
          'yaymatik',
        )}
      </Hint>
    </div>
  );
}
