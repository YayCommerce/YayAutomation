import { CircleAlert } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ErrorAlert } from '@/routes/workflows/shared/ui/ErrorAlert';

export function SettingsErrorAlert({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <ErrorAlert>
      <CircleAlert aria-hidden />
      <AlertDescription className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
        <span>{message}</span>
        {onRetry && (
          <Button variant="outline" size="sm" onClick={onRetry}>
            {__('Try again', 'yaymatik')}
          </Button>
        )}
      </AlertDescription>
    </ErrorAlert>
  );
}
