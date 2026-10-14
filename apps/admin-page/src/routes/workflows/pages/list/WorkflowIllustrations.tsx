import { cn } from '@/lib/utils';

/** Colors come from theme-token classes (`fill-*`, `stroke-*`), so the illustration follows dark mode. */

/** Trigger card branching into two steps. Used by the workflow list empty state. */
export function WorkflowsEmptyIllustration({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 200 140" aria-hidden="true" className={cn('h-35 w-50', className)}>
      <circle cx="100" cy="70" r="64" className="fill-muted" />
      <path
        d="M100 52v18M60 70h80M60 70v16M140 70v16M60 114v26M140 114v26"
        className="stroke-border fill-none"
        strokeWidth="3"
      />

      <rect x="46" y="18" width="108" height="34" rx="6" className="fill-card stroke-border" />
      <path d="M58 43l7-13 7 13z" className="fill-success" />
      <rect x="80" y="32" width="44" height="5" rx="2.5" className="fill-muted" />
      <circle cx="140" cy="34.5" r="3" className="fill-muted" />

      <rect x="16" y="86" width="76" height="28" rx="5" className="fill-card stroke-border" />
      <rect x="26" y="95" width="10" height="10" rx="2" className="fill-destructive/70" />
      <rect x="42" y="98" width="38" height="4" rx="2" className="fill-muted" />

      <rect x="108" y="86" width="76" height="28" rx="5" className="fill-card stroke-border" />
      <circle cx="123" cy="100" r="5" className="fill-warning" />
      <rect x="134" y="98" width="38" height="4" rx="2" className="fill-muted" />
    </svg>
  );
}
