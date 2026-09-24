import { ArrowLeft } from 'lucide-react';
import { Link, useParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

export default function WorkflowEditorPage() {
  const { workflowId } = useParams();

  return (
    // Fill the viewport below the WP admin bar (32px) and plugin header (h-13.5).
    <div className="flex h-[calc(100vh-32px-3.375rem)] flex-col">
      <div className="bg-background flex h-12 shrink-0 items-center gap-3 border-t px-4">
        <Button variant="ghost" size="icon-sm" asChild>
          <Link to="/workflows" aria-label={__('Back to workflows', 'yay-automation')}>
            <ArrowLeft />
          </Link>
        </Button>
        <span className="font-medium">{__('Untitled workflow', 'yay-automation')}</span>
        <Badge variant="secondary">{workflowId}</Badge>
      </div>

      <div className="text-muted-foreground flex flex-1 items-center justify-center">
        {__('Canvas coming soon', 'yay-automation')}
      </div>
    </div>
  );
}
