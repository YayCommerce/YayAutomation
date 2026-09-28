import type { MouseEventHandler, ReactNode } from 'react';
import { ArrowLeft } from 'lucide-react';
import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

export interface EditorBackProps {
  backTo?: string;
  backLabel?: string;
  /** Runs before navigation. Call `preventDefault()` to handle it yourself (e.g. `navigate(-1)`). */
  onBack?: MouseEventHandler<HTMLAnchorElement>;
}

/** Full-height editor shell: toolbar row + canvas area. Shared by the editor and template preview. */
export function EditorLayout({
  toolbar,
  children,
  backTo = '/workflows',
  backLabel = __('Back to workflows', 'yay-automation'),
  onBack,
}: EditorBackProps & { toolbar: ReactNode; children: ReactNode }) {
  return (
    // Fill the viewport below the WP admin bar (32px) and plugin header (h-13.5).
    <div className="flex h-[calc(100vh-32px-3.375rem)] flex-col">
      <div className="bg-background flex h-12 shrink-0 items-center gap-3 border-t px-4">
        <Button variant="ghost" size="icon-sm" asChild>
          <Link to={backTo} aria-label={backLabel} onClick={onBack}>
            <ArrowLeft />
          </Link>
        </Button>
        {toolbar}
      </div>

      <div className="min-h-0 flex-1 border-t">{children}</div>
    </div>
  );
}

/** Loading or error state for a page built on `EditorLayout`. */
export function EditorPlaceholder({ error, ...backProps }: EditorBackProps & { error: Error | null }) {
  return (
    <EditorLayout {...backProps} toolbar={!error && <Skeleton className="h-5 w-48" />}>
      {error ? (
        <div className="text-muted-foreground flex size-full items-center justify-center">{error.message}</div>
      ) : (
        <Skeleton className="size-full rounded-none" />
      )}
    </EditorLayout>
  );
}
