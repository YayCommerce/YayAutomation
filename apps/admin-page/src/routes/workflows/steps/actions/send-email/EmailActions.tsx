import { useEffect, useState } from 'react';
import type { EmailPreview, WorkflowIssue } from '@yaymatik/types';
import { ChevronDown, CircleAlert, Eye, Info, Loader2, Send } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button, LoadingButton } from '@/components/ui/button';
import { ButtonGroup, ButtonGroupSeparator } from '@/components/ui/button-group';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { toast } from '@/components/ui/sonner';
import { issuesOf, useEmailTools } from '../../core/fields/emailTools';
import { PreviewDialog, sampleProductsText } from './PreviewDialog';

const testRecipient = window.yayMatikMeta.wp.userEmail;

/**
 * In the Send email panel header: "Preview" with a menu (Preview email, Send test email) for the
 * email as it is in the editor. A step the server refuses lists its issues here instead.
 */
export function EmailActions() {
  const tools = useEmailTools();
  const [busy, setBusy] = useState<'preview' | 'test' | null>(null);
  const [issues, setIssues] = useState<WorkflowIssue[]>([]);
  const [preview, setPreview] = useState<EmailPreview | null>(null);
  // An edit may have fixed them; the next Preview or Send test checks again.
  const config = tools?.config;
  useEffect(() => setIssues([]), [config]);

  if (!tools) return null;

  const run = async (action: 'preview' | 'test') => {
    setBusy(action);
    setIssues([]);
    try {
      if (action === 'preview') {
        setPreview(await tools.preview());
      } else {
        const result = await tools.sendTest();
        const description = result.sample
          ? __('Sample data', 'yaymatik')
          : result.sampleProducts.length > 0
            ? sampleProductsText(result.sampleProducts)
            : '';
        toast.success(
          /* translators: %s: email address */
          sprintf(__('Test email sent to %s', 'yaymatik'), result.sentTo),
          description ? { description } : {},
        );
      }
    } catch (error) {
      const found = issuesOf(error);
      if (found.length) {
        setIssues(found);
        setPreview(null);
      } else toast.error(error instanceof Error ? error.message : String(error));
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center justify-end gap-3">
        {testRecipient && (
          <span className="text-muted-foreground min-w-0 truncate text-xs" title={testRecipient}>
            {
              /* translators: %s: email address */
              sprintf(__('Test goes to %s', 'yaymatik'), testRecipient)
            }
          </span>
        )}
        <ButtonGroup className="shrink-0">
          <LoadingButton size="sm" loading={busy === 'preview'} disabled={!!busy} onClick={() => run('preview')}>
            <Eye />
            {__('Preview', 'yaymatik')}
          </LoadingButton>
          <ButtonGroupSeparator className="bg-primary-foreground/30" />
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button size="sm" className="px-2" disabled={!!busy} aria-label={__('More email actions', 'yaymatik')}>
                {busy === 'test' ? <Loader2 className="animate-spin" /> : <ChevronDown />}
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
              <DropdownMenuItem onSelect={() => run('preview')}>
                <Eye />
                {__('Preview email', 'yaymatik')}
              </DropdownMenuItem>
              <DropdownMenuItem className="items-start" onSelect={() => run('test')}>
                <Send className="mt-0.5" />
                <span className="flex flex-col">
                  {__('Send test email', 'yaymatik')}
                  <span className="text-muted-foreground text-xs">
                    {__('To you, subject starts with [Test]', 'yaymatik')}
                  </span>
                </span>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </ButtonGroup>
      </div>
      {issues.length > 0 && (
        <Alert destructive className="border-destructive/30 bg-destructive/10 border">
          <CircleAlert aria-hidden />
          <AlertTitle>{__('Fix this step first', 'yaymatik')}</AlertTitle>
          <AlertDescription>
            <ul className="list-disc pl-4">
              {issues.map((issue) => (
                <li key={`${issue.code}|${issue.field ?? ''}|${issue.message}`}>{issue.message}</li>
              ))}
            </ul>
          </AlertDescription>
        </Alert>
      )}
      <PreviewDialog
        preview={preview}
        sending={busy === 'test'}
        onSendTest={() => run('test')}
        onClose={() => setPreview(null)}
      />
    </div>
  );
}

/** Under the Message field. */
export function UnsubscribeNote() {
  return (
    <p className="text-muted-foreground flex items-center gap-1.5 text-[12.5px]">
      <Info className="size-3.5 shrink-0" aria-hidden />
      {__('An unsubscribe link is added automatically.', 'yaymatik')}
    </p>
  );
}
