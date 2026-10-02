import { cn } from '@/lib/utils';

/**
 * A trigger card leading to a step and a results list, on a disc. Theme-token classes (`fill-*`,
 * `stroke-*`) keep it right in dark mode.
 */
export function WorkflowRunsIllustration({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 220 160" aria-hidden="true" className={cn('h-40 w-55', className)}>
      {/* Tinted from the text color, so the disc shows on both white and gray page backgrounds. */}
      <circle cx="110" cy="80" r="78" className="fill-foreground/6" />
      <path d="M45 46v20M148 46v12" className="stroke-border fill-none" strokeWidth="3" />

      <rect x="40" y="16" width="110" height="30" rx="6" className="fill-card stroke-border" />
      <path d="M52 37l7-12 7 12z" className="fill-success" />
      <rect x="74" y="28" width="40" height="5" rx="2.5" className="fill-muted" />
      <circle cx="134" cy="30.5" r="3" className="fill-muted" />

      <rect x="14" y="66" width="62" height="26" rx="5" className="fill-card stroke-border" />
      <rect x="23" y="74" width="10" height="10" rx="2" className="fill-destructive/70" />
      <rect x="39" y="77" width="28" height="4" rx="2" className="fill-muted" />

      <rect x="96" y="58" width="104" height="84" rx="6" className="fill-card stroke-border" />
      {[74, 90, 106, 122].map((y) => (
        <g key={y}>
          <circle cx="110" cy={y} r="4" className="fill-muted" />
          <rect x="122" y={y - 2} width="40" height="4" rx="2" className="fill-muted" />
          <rect x="168" y={y - 2} width="20" height="4" rx="2" className="fill-muted" />
        </g>
      ))}
    </svg>
  );
}
