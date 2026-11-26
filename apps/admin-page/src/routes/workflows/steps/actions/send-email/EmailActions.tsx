import { useEffect, useState } from 'react';
import type { EmailPreview, WorkflowIssue } from '@yaymatik/types';
import { ChevronDown, CircleAlert, Eye, Info, Loader2, Pencil, Send } from 'lucide-react';
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
import { FieldDialog } from '@/components/widget/FieldDialog';
import { issuesOf, useEmailTools } from '../../core/fields/emailTools';
import { PreviewDialog, sampleProductsText } from './PreviewDialog';
import { saveTestEmail } from './testEmail';

/** Kept for the page's life: the next email step's panel starts from a newly saved address. */
let savedTestRecipient = window.yayMatikMeta.wp.testEmail;

/**
 * In the Send email panel header: "Preview" with a menu (Preview email, Send test email) for the
 * email as it is in the editor. A step the server refuses lists its issues here instead.
 */
export function EmailActions() {
  const tools = useEmailTools();
  const [busy, setBusy] = useState<'preview' | 'test' | null>(null);
  const [issues, setIssues] = useState<WorkflowIssue[]>([]);
  const [preview, setPreview] = useState<EmailPreview | null>(null);
  const [testRecipient, setTestRecipient] = useState(savedTestRecipient);
  const [editingRecipient, setEditingRecipient] = useState(false);
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
          ? __('Sample data', 'yaymatik-automation-for-woocommerce')
          : result.sampleProducts.length > 0
            ? sampleProductsText(result.sampleProducts)
            : '';
        toast.success(
          /* translators: %s: email address */
          sprintf(__('Test email sent to %s', 'yaymatik-automation-for-woocommerce'), result.sentTo),
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
        <span className="group flex min-w-0 items-center gap-1">
          {testRecipient && (
            <span className="text-muted-foreground min-w-0 truncate text-xs" title={testRecipient}>
              {
                /* translators: %s: email address */
                sprintf(__('Test goes to %s', 'yaymatik-automation-for-woocommerce'), testRecipient)
              }
            </span>
          )}
          <Button
            variant="ghost"
            size="icon"
            className="size-6 shrink-0 opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
            aria-label={__('Change test recipient', 'yaymatik-automation-for-woocommerce')}
            onClick={() => setEditingRecipient(true)}
          >
            <Pencil className="size-3.5" />
          </Button>
        </span>
        <ButtonGroup className="shrink-0">
          <LoadingButton size="sm" loading={busy === 'preview'} disabled={!!busy} onClick={() => run('preview')}>
            <Eye />
            {__('Preview', 'yaymatik-automation-for-woocommerce')}
          </LoadingButton>
          <ButtonGroupSeparator className="bg-primary-foreground/30" />
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                size="sm"
                className="px-2"
                disabled={!!busy}
                aria-label={__('More email actions', 'yaymatik-automation-for-woocommerce')}
              >
                {busy === 'test' ? <Loader2 className="animate-spin" /> : <ChevronDown />}
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
              <DropdownMenuItem onSelect={() => run('preview')}>
                <Eye />
                {__('Preview email', 'yaymatik-automation-for-woocommerce')}
              </DropdownMenuItem>
              <DropdownMenuItem className="items-start" onSelect={() => run('test')}>
                <Send className="mt-0.5" />
                <span className="flex flex-col">
                  {__('Send test email', 'yaymatik-automation-for-woocommerce')}
                  <span className="text-muted-foreground text-xs">
                    {__('Subject starts with [Test]', 'yaymatik-automation-for-woocommerce')}
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
          <AlertTitle>{__('Fix this step first', 'yaymatik-automation-for-woocommerce')}</AlertTitle>
          <AlertDescription>
            <ul className="list-disc pl-4">
              {issues.map((issue) => (
                <li key={`${issue.code}|${issue.field ?? ''}|${issue.message}`}>{issue.message}</li>
              ))}
            </ul>
          </AlertDescription>
        </Alert>
      )}
      {editingRecipient && (
        <TestRecipientDialog
          initialValue={testRecipient}
          onSaved={(email) => {
            savedTestRecipient = email;
            setTestRecipient(email);
            setEditingRecipient(false);
          }}
          onClose={() => setEditingRecipient(false)}
        />
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

function TestRecipientDialog({
  initialValue,
  onSaved,
  onClose,
}: {
  initialValue: string;
  onSaved: (email: string) => void;
  onClose: () => void;
}) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string>();

  const save = async (email: string) => {
    setPending(true);
    setError(undefined);
    try {
      onSaved(await saveTestEmail(email));
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : String(reason));
      setPending(false);
    }
  };

  return (
    <FieldDialog
      title={__('Send test to', 'yaymatik-automation-for-woocommerce')}
      description={__('Enter the email address test emails go to.', 'yaymatik-automation-for-woocommerce')}
      label={__('Email', 'yaymatik-automation-for-woocommerce')}
      initialValue={initialValue}
      type="email"
      pending={pending}
      error={error}
      onSave={save}
      onClose={onClose}
    />
  );
}

/** Under the Message field. */
export function UnsubscribeNote() {
  return (
    <p className="text-muted-foreground flex items-center gap-1.5 text-[12.5px]">
      <Info className="size-3.5 shrink-0" aria-hidden />
      {__('An unsubscribe link is added automatically.', 'yaymatik-automation-for-woocommerce')}
    </p>
  );
}
