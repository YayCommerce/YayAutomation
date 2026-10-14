import { cn } from '@/lib/utils';
import { comingSoonLabel } from '../../steps/core/pending';

/** "Coming soon" as a small muted chip: on template cards, picker rows and the describe box. */
export function ComingSoonChip({ className }: { className?: string }) {
  return (
    <span
      className={cn(
        'bg-muted text-muted-foreground inline-flex h-5 shrink-0 items-center rounded-md px-1.75 text-[11.5px] font-medium whitespace-nowrap',
        className,
      )}
    >
      {comingSoonLabel}
    </span>
  );
}
