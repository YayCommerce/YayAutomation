import { useEffect, type MouseEventHandler, type ReactNode } from 'react';
import { Workflow, X } from 'lucide-react';
import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

/** On `<html>` while an editor is open: the page doesn't scroll (preflight.css). */
const EDITOR_OPEN_CLASS = 'yaymatik-editor-open';

export interface EditorCloseProps {
  closeTo?: string;
  closeLabel?: string;
  /** Runs before navigation. Call `preventDefault()` to handle it yourself (e.g. `navigate(-1)`). */
  onClose?: MouseEventHandler<HTMLAnchorElement>;
}

/**
 * Full-height editor shell: header row + canvas area. Shared by the editor and template preview.
 * Header = app icon + `title` on the left, `actions` then × on the right.
 */
export function EditorLayout({
  title,
  actions,
  children,
  closeTo = '/workflows',
  closeLabel = __('Close', 'yaymatik-automation-for-woocommerce'),
  onClose,
}: EditorCloseProps & { title: ReactNode; actions?: ReactNode; children: ReactNode }) {
  useEffect(() => {
    // WP's admin menu, when taller than the window, makes the page scroll; a click on the canvas then
    // scrolls the editor's header under the plugin header. The editor fills the window, so the page stays put.
    document.documentElement.classList.add(EDITOR_OPEN_CLASS);
    window.scrollTo(0, 0);
    return () => document.documentElement.classList.remove(EDITOR_OPEN_CLASS);
  }, []);

  return (
    // Fill the viewport below the WP admin bar (--wp-admin--admin-bar--height) and plugin header (h-13.5).
    <div className="flex h-[calc(100vh-var(--wp-admin--admin-bar--height,32px)-3.375rem)] flex-col">
      <div className="bg-background flex h-12 shrink-0 items-center gap-2 px-4">
        <WorkflowAppIcon />
        <div className="flex min-w-0 flex-1 items-center gap-2">{title}</div>
        {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
        {/* Round outline, same height as the `sm` actions beside it. `outline` carries the dark-mode colors. */}
        <Button variant="outline" size="icon-sm" className="rounded-full" asChild>
          <Link to={closeTo} aria-label={closeLabel} onClick={onClose}>
            <X strokeWidth={2.5} />
          </Link>
        </Button>
      </div>

      <div className="min-h-0 flex-1 border-t">{children}</div>
    </div>
  );
}

/** Small app tile at the start of the editor and Overview headers. */
export function WorkflowAppIcon() {
  return (
    <span className="bg-primary/10 text-primary flex size-6 shrink-0 items-center justify-center rounded-md">
      <Workflow className="size-3.5" aria-hidden />
    </span>
  );
}

/** Loading or error state for a page built on `EditorLayout`. */
export function EditorPlaceholder({ error, ...closeProps }: EditorCloseProps & { error: Error | null }) {
  return (
    <EditorLayout {...closeProps} title={!error && <Skeleton className="h-5 w-48" />}>
      {error ? (
        <div className="text-muted-foreground flex size-full items-center justify-center">{error.message}</div>
      ) : (
        <Skeleton className="size-full rounded-none" />
      )}
    </EditorLayout>
  );
}
