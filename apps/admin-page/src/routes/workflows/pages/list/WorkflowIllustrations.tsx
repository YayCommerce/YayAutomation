import { cn } from '@/lib/utils';

/** Colors come from theme-token classes (`fill-*`, `stroke-*`), so both illustrations follow dark mode. */

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

/** Connected cards on a disc. Sits on the `bg-primary` hero of the template library. */
export function TemplatesHeroIllustration({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 220 180" aria-hidden="true" className={cn('h-45 w-55', className)}>
      <circle cx="110" cy="90" r="86" className="fill-primary-foreground/15" />
      <path
        d="M150 40c30 4 36 40 10 52M60 128c-26-6-26-40 0-44"
        className="stroke-primary-foreground/70 fill-none"
        strokeWidth="3"
        strokeLinecap="round"
      />

      <rect x="70" y="24" width="80" height="48" rx="6" className="fill-primary-foreground" />
      <circle cx="90" cy="48" r="10" className="fill-primary/25" />
      <rect x="106" y="40" width="34" height="5" rx="2.5" className="fill-primary/25" />
      <rect x="106" y="51" width="22" height="5" rx="2.5" className="fill-primary/15" />
      <circle cx="150" cy="40" r="7" className="fill-primary stroke-primary-foreground" strokeWidth="2" />

      <rect x="124" y="84" width="68" height="40" rx="6" className="fill-primary-foreground" />
      <rect x="134" y="94" width="12" height="8" rx="2" className="fill-destructive/70" />
      <rect x="134" y="110" width="48" height="4" rx="2" className="fill-primary/20" />

      <rect x="60" y="96" width="50" height="62" rx="6" className="fill-primary-foreground" />
      <path d="M85 108c8 8 10 22 0 30-10-8-8-22 0-30z" className="fill-warning" />
      <rect x="72" y="146" width="26" height="4" rx="2" className="fill-success/60" />
      <circle cx="60" cy="128" r="7" className="fill-primary stroke-primary-foreground" strokeWidth="2" />
    </svg>
  );
}

/** Step cards on a disc, one picked by a cursor. Sits above the Create workflow prompt. */
export function CreateWorkflowIllustration({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 160 110" aria-hidden="true" className={cn('h-28 w-40', className)}>
      <circle cx="78" cy="56" r="48" className="fill-muted" />

      <rect x="46" y="18" width="62" height="20" rx="7" className="fill-card stroke-border" />
      <rect x="56" y="26" width="30" height="4" rx="2" className="fill-muted-foreground/30" />
      <circle cx="97" cy="28" r="3" className="fill-muted-foreground/30" />

      <rect x="10" y="62" width="62" height="20" rx="7" className="fill-card stroke-border" />
      <rect x="20" y="70" width="30" height="4" rx="2" className="fill-muted-foreground/30" />
      <circle cx="61" cy="72" r="3" className="fill-muted-foreground/30" />

      <rect x="84" y="48" width="64" height="22" rx="7" className="fill-card stroke-foreground" strokeWidth="2" />
      <rect x="94" y="57" width="30" height="4" rx="2" className="fill-muted-foreground/30" />
      <circle cx="137" cy="59" r="3" className="fill-muted-foreground/30" />

      <path d="M136 64l0 14 4-4 3 6 3-1.5-3-6 5.5-.5z" className="fill-foreground stroke-card" strokeWidth="1" />
    </svg>
  );
}
