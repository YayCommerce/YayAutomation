import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';

/** "Coming soon" as a small muted chip, on the describe box. */
export function ComingSoonChip({ className }: { className?: string }) {
  return (
    <span
      className={cn(
        'bg-muted text-muted-foreground inline-flex h-5 shrink-0 items-center rounded-md px-1.75 text-[11.5px] font-medium whitespace-nowrap',
        className,
      )}
    >
      {__('Coming soon', 'yaymatik-automation-for-woocommerce')}
    </span>
  );
}
