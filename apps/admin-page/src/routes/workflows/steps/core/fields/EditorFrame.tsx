import { useContext, useRef, useState } from 'react';
import type { StepId } from '@yaymatik/types';
import { Info, Paintbrush, X } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button, LoadingButton } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { toast } from '@/components/ui/sonner';
import type { Config } from '../types';
import { useEditorTools } from './editorTools';
import { useEditorChannel } from './useEditorChannel';
import { VariableScopeContext } from './variableScope';

/** A per-open channel id. Not `crypto.randomUUID()`: it's undefined on non-HTTPS WP sites. */
const newChannel = () =>
  Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');

/** The plugin's URL with `yaymatik_embed=1&yaymatik_channel=…` in its query, before any `#`. */
export const embedUrl = (url: string, channel: string) => {
  const embedded = new URL(url, window.location.href);
  embedded.searchParams.set('yaymatik_embed', '1');
  embedded.searchParams.set('yaymatik_channel', channel);
  return embedded.toString();
};

/**
 * A saved value the select doesn't list yet (a design made in the editor) joins its options for this page
 * load, so the form accepts it and shows its label. The server reads the plugin's options afresh.
 */
const remember = (stepId: string, field: string, value: string | number, label?: string) => {
  const meta = window.yayMatikMeta.automation.steps[stepId as StepId]?.fields.find((f) => f.key === field);
  if (meta?.type !== 'select' || !meta.options) return;
  const option = meta.options.find((o) => o.value === String(value));
  if (option) option.label = label ?? option.label;
  else meta.options.push({ value: String(value), label: label ?? String(value) });
};

interface Opened {
  url: string;
  channel: string;
  sample: { orderId: number | null; userId: number | null };
}

/** Under another plugin's step form: its editor button and the note that designs aren't versioned (§5.6). */
export function editorFooter(stepId: string, editor: { field: string; button: string }, title: string) {
  return function EditorFooter({ config }: { config: Config }) {
    const tools = useEditorTools();
    const { variables } = useContext(VariableScopeContext);
    const [opened, setOpened] = useState<Opened | null>(null);
    const [opening, setOpening] = useState(false);
    if (!tools) return null;

    const open = async () => {
      setOpening(true);
      try {
        const target = await tools.open();
        setOpened({ ...target, channel: newChannel() });
      } catch (error) {
        toast.error(error instanceof Error ? error.message : String(error));
      } finally {
        setOpening(false);
      }
    };

    return (
      <div className="flex flex-col gap-2">
        <LoadingButton variant="outline" size="sm" className="self-start" loading={opening} onClick={open}>
          <Paintbrush />
          {editor.button}
        </LoadingButton>
        <p className="text-muted-foreground flex items-center gap-1.5 text-[12.5px]">
          <Info className="size-3.5 shrink-0" aria-hidden />
          {__('Design changes apply to live emails at once.', 'yaymatik')}
        </p>
        {opened && (
          <EditorFrame
            title={title}
            opened={opened}
            init={{
              value: config[editor.field] ?? '',
              config,
              sample: opened.sample,
              locale: document.documentElement.lang || 'en-US',
              variables: variables.map((variable) => ({
                path: variable.key,
                label: variable.label,
                type: variable.type,
              })),
            }}
            onSave={(value, saved) => {
              remember(stepId, editor.field, value, saved);
              setOpened(null);
              tools.save(editor.field, value);
            }}
            onClose={() => setOpened(null)}
          />
        )}
      </div>
    );
  };
}

/** The plugin's page over the editor; asks before closing with unsaved changes. */
export function EditorFrame({
  title,
  opened,
  init,
  onSave,
  onClose,
}: {
  title: string;
  opened: Opened;
  init: Record<string, unknown>;
  onSave: (value: string | number, label?: string) => void;
  onClose: () => void;
}) {
  const frame = useRef<HTMLIFrameElement>(null);
  const [dirty, setDirty] = useState(false);
  const [error, setError] = useState('');
  const [confirming, setConfirming] = useState(false);
  const close = () => (dirty ? setConfirming(true) : onClose());

  useEditorChannel(frame, opened.channel, init, (message) => {
    if (message.type === 'change') {
      setDirty(message.dirty);
      setError('');
    } else if (message.type === 'save') onSave(message.value, message.label);
    else if (message.type === 'cancel') close();
    else setError(message.message);
  });

  return (
    <Dialog open onOpenChange={(open) => !open && close()}>
      {/* `nokey`: keys pressed in here mustn't reach ReactFlow's delete-selected-step shortcut. */}
      <DialogContent className="nokey inset-4 flex size-auto max-w-none translate-0 flex-col gap-0 overflow-hidden rounded-xl border-0 p-0 shadow-[0_24px_64px_rgb(0_0_0/0.25)] *:data-[slot=dialog-close]:hidden sm:max-w-none">
        <div className="flex items-center justify-between gap-4 border-b px-5 py-3">
          <div className="flex min-w-0 items-center gap-3">
            <DialogTitle className="shrink-0 text-base font-semibold">{title}</DialogTitle>
            <DialogDescription className={error ? 'text-destructive truncate text-xs' : 'sr-only'}>
              {error || __('Save in the editor to use the design in this step.', 'yaymatik')}
            </DialogDescription>
          </div>
          <Button variant="ghost" size="icon-sm" aria-label={__('Close', 'yaymatik')} onClick={close}>
            <X />
          </Button>
        </div>
        {/* No `sandbox`: the plugin's own admin page on this site (same origin). */}
        <iframe ref={frame} title={title} src={embedUrl(opened.url, opened.channel)} className="min-h-0 flex-1" />
        <AlertDialog open={confirming} onOpenChange={setConfirming}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>{__('Discard your changes?', 'yaymatik')}</AlertDialogTitle>
              <AlertDialogDescription>
                {__('The editor has changes you haven’t saved. Close it anyway?', 'yaymatik')}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>{__('Keep editing', 'yaymatik')}</AlertDialogCancel>
              <AlertDialogAction onClick={onClose}>{__('Discard', 'yaymatik')}</AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </DialogContent>
    </Dialog>
  );
}
