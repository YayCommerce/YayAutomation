import { ExternalLink } from 'lucide-react';
import { __ } from '@wordpress/i18n';

/** Where "Get YayMatik Pro" goes. */
export const UPGRADE_URL = 'https://yaycommerce.com/yaymatik/';

const lines = {
  library: __(
    '10 more templates: cart series, cross-sell and upsell, review reminders, welcome series and win-back.',
    'yaymatik',
  ),
  trigger: __('Customer inactive, order count reaches and total spend reaches.', 'yaymatik'),
  action: __(
    'Wait until a day and time, resend a WooCommerce email, update custom fields and change customer roles.',
    'yaymatik',
  ),
};

/** The free edition's one "PRO" group, last in the template library and in the trigger and action pickers. */
export function ProTeaser({ place }: { place: keyof typeof lines }) {
  return (
    <section className={place === 'library' ? 'mt-6' : 'mt-1'}>
      <h3 className="text-muted-foreground px-2 pt-2.5 pb-1 text-[11px] font-semibold tracking-wider uppercase">
        {__('PRO', 'yaymatik')}
      </h3>
      <a
        href={UPGRADE_URL}
        target="_blank"
        rel="noopener"
        className="hover:bg-accent flex items-start gap-3 rounded-lg p-2 text-sm"
      >
        <span className="min-w-0 flex-1">
          <span className="block font-medium">{__('Get YayMatik Pro', 'yaymatik')}</span>
          <span className="text-muted-foreground block text-xs">{lines[place]}</span>
        </span>
        <ExternalLink className="text-muted-foreground mt-0.5 size-3.5 shrink-0" aria-hidden />
      </a>
    </section>
  );
}
