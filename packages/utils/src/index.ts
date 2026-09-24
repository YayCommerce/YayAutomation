import { clsx, type ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

const twMerge = extendTailwindMerge({
  extend: {
    classGroups: {
      transition: ['transition-default'],
    },
  },
});

/**
 * Merges Tailwind classes safely, resolving conflicts.
 * Drop-in replacement for `clsx` + `twMerge` together.
 */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/**
 * Returns the WP site timezone as an IANA string (e.g. "America/New_York")
 * or a UTC offset string (e.g. "UTC+5.5").
 *
 * Reads from a localized meta object passed via `window[metaKey]?.wp`,
 * then falls back to `window.wpDateSettings` injected by WordPress core.
 */
export function getWpTimezone(metaKey: string = 'yayAutomationMeta'): string {
  const globalMeta = (window as unknown as Record<string, unknown>)[metaKey] as
    | { wp?: { timezoneString?: string; gmtOffset?: number } }
    | undefined;
  const meta = globalMeta?.wp;

  if (meta?.timezoneString) return meta.timezoneString;
  if (meta?.gmtOffset !== undefined) {
    const offset = meta.gmtOffset;
    return offset === 0 ? 'UTC' : `UTC${offset > 0 ? '+' : ''}${offset}`;
  }

  const wpSettings = (window as { wpDateSettings?: { timezone?: { string?: string; offset?: string } } })
    .wpDateSettings;
  if (wpSettings?.timezone?.string) return wpSettings.timezone.string;
  if (wpSettings?.timezone?.offset) return `UTC${wpSettings.timezone.offset}`;
  return 'UTC';
}
