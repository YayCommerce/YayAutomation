import type { ReactNode } from 'react';
import { ArrowLeft } from 'lucide-react';
import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';

/** Full-height editor shell: toolbar row + canvas area. Shared by the loading and loaded states. */
export function EditorLayout({ toolbar, children }: { toolbar: ReactNode; children: ReactNode }) {
  return (
    // Fill the viewport below the WP admin bar (32px) and plugin header (h-13.5).
    <div className="flex h-[calc(100vh-32px-3.375rem)] flex-col">
      <div className="bg-background flex h-12 shrink-0 items-center gap-3 border-t px-4">
        <Button variant="ghost" size="icon-sm" asChild>
          <Link to="/workflows" aria-label={__('Back to workflows', 'yay-automation')}>
            <ArrowLeft />
          </Link>
        </Button>
        {toolbar}
      </div>

      <div className="min-h-0 flex-1 border-t">{children}</div>
    </div>
  );
}
