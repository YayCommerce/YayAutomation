import { useCallback, useEffect, useRef, useState } from 'react';
import type { EmailPreview, ProductGridKind } from '@yaymatik/types';
import { Monitor, Send, Smartphone, X } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button, LoadingButton } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';

type Device = 'desktop' | 'mobile';

/**
 * The iframe's width on each device. Desktop: the whole area, like a mail client's reading pane,
 * and never 600px or less, where WooCommerce's `@media (max-width: 600px)` rules shrink the text;
 * the email's own 600px table centers itself. Mobile: a common phone, where those rules apply.
 */
const deviceFrame: Record<Device, { width: number | '100%'; minWidth?: number }> = {
  desktop: { width: '100%', minWidth: 640 },
  mobile: { width: 375 },
};

const sender = window.yayMatikMeta.automation.email;

/**
 * The email as a mail client shows it: the header (sender, subject, To), then the email on a grey
 * page. The server's HTML goes into the iframe unchanged, so it looks as it does when sent.
 */
export function PreviewDialog({
  preview,
  sending,
  onSendTest,
  onClose,
}: {
  preview: EmailPreview | null;
  sending: boolean;
  onSendTest: () => void;
  onClose: () => void;
}) {
  const [device, setDevice] = useState<Device>('desktop');
  const content = useRef<HTMLDivElement>(null);
  const senderName = sender.fromName || sender.fromAddress;

  return (
    <Dialog open={!!preview} onOpenChange={(open) => !open && onClose()}>
      {/* 32px from the viewport edges; its own close button in the header row. `nokey`: keys pressed
          in here mustn't reach ReactFlow's delete-selected-step shortcut. */}
      <DialogContent
        ref={content}
        className="nokey inset-8 flex size-auto max-w-none translate-0 flex-col gap-0 overflow-hidden rounded-xl border-0 p-0 shadow-[0_24px_64px_rgb(0_0_0/0.25)] *:data-[slot=dialog-close]:hidden sm:max-w-none"
        // The dialog itself takes focus, so no control shows a focus ring until the keyboard is used.
        onOpenAutoFocus={(event) => {
          event.preventDefault();
          content.current?.focus();
        }}
      >
        {preview && (
          <>
            <div className="flex items-center justify-between gap-4 border-b px-5 py-3.5">
              <div className="flex min-w-0 items-center gap-2.5">
                <DialogTitle className="shrink-0 text-base font-semibold">
                  {__('Email preview', 'yaymatik')}
                </DialogTitle>
                <DialogDescription
                  className={
                    preview.sample
                      ? 'bg-warning-soft text-warning-soft-foreground shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold'
                      : 'truncate text-xs'
                  }
                >
                  {preview.sample
                    ? __('Sample data', 'yaymatik')
                    : preview.order
                      ? sprintf(
                          /* translators: %s: order number */
                          __('Order #%s data · nothing is sent', 'yaymatik'),
                          preview.order,
                        )
                      : preview.customer
                        ? sprintf(
                            /* translators: %s: customer name */
                            __('Customer %s data · nothing is sent', 'yaymatik'),
                            preview.customer,
                          )
                        : __('Nothing is sent', 'yaymatik')}
                </DialogDescription>
                {!preview.sample && preview.sampleProducts.length > 0 && (
                  <span className="bg-warning-soft text-warning-soft-foreground truncate rounded-full px-2 py-0.5 text-[11px] font-semibold">
                    {sampleProductsText(preview.sampleProducts)}
                  </span>
                )}
              </div>
              <div className="flex shrink-0 items-center gap-2">
                <DeviceToggle value={device} onChange={setDevice} />
                <LoadingButton
                  variant="outline"
                  size="sm"
                  className="rounded-md text-[13px]"
                  loading={sending}
                  disabled={sending}
                  onClick={onSendTest}
                >
                  <Send />
                  {__('Send test', 'yaymatik')}
                </LoadingButton>
                <DialogClose asChild>
                  <Button variant="ghost" size="icon-sm" className="rounded-md" aria-label={__('Close', 'yaymatik')}>
                    <X />
                  </Button>
                </DialogClose>
              </div>
            </div>

            <div className="flex items-start gap-3.5 border-b px-6 py-4">
              <span
                aria-hidden
                className="bg-avatar-soft text-avatar-soft-foreground flex size-10 shrink-0 items-center justify-center rounded-full text-[15px] font-semibold"
              >
                {senderName.charAt(0).toUpperCase()}
              </span>
              <div className="flex min-w-0 flex-1 flex-col gap-0.75">
                <p className="text-xl font-semibold wrap-break-word">{preview.subject}</p>
                <p className="truncate text-[13px]">
                  <b className="font-semibold">{senderName}</b>
                  {sender.fromName && <span className="text-muted-foreground"> &lt;{sender.fromAddress}&gt;</span>}
                </p>
                <p className="text-muted-foreground truncate text-[13px]">
                  {
                    /* translators: %s: email address */
                    sprintf(__('to %s', 'yaymatik'), preview.to || '—')
                  }
                </p>
              </div>
            </div>

            {/* The only scroll box: the iframe is as tall as the email. */}
            <div className="bg-email-canvas flex min-h-0 flex-1 justify-center overflow-auto px-6 py-8">
              <EmailFrame html={preview.html} {...deviceFrame[device]} />
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}

const devices: { value: Device; label: string; icon: typeof Monitor }[] = [
  { value: 'desktop', label: __('Desktop', 'yaymatik'), icon: Monitor },
  { value: 'mobile', label: __('Mobile', 'yaymatik'), icon: Smartphone },
];

/** Desktop / Mobile on a muted pill; the chosen one a raised chip. Focus ring from the keyboard only. */
function DeviceToggle({ value, onChange }: { value: Device; onChange: (device: Device) => void }) {
  return (
    <div role="group" aria-label={__('Preview width', 'yaymatik')} className="bg-muted flex rounded-md p-0.75">
      {devices.map(({ value: device, label, icon: Icon }) => {
        const on = device === value;
        return (
          <button
            key={device}
            type="button"
            aria-pressed={on}
            onClick={() => onChange(device)}
            className={cn(
              'focus-visible:ring-ring/50 flex h-6.5 cursor-pointer items-center gap-1.5 rounded-sm px-2.5 text-xs font-medium outline-none focus-visible:ring-2',
              on
                ? 'bg-background text-foreground shadow-[0_1px_2px_rgb(0_0_0/0.08)]'
                : 'text-muted-foreground hover:text-foreground',
            )}
          >
            <Icon className="size-3.75" aria-hidden />
            {label}
          </button>
        );
      })}
    </div>
  );
}

/** Links in the email open in a new tab; the unsubscribe link (`#`) and others go nowhere. */
function openLinks(event: MouseEvent) {
  const link = (event.target as Element | null)?.closest?.('a[href]') as HTMLAnchorElement | null;
  if (!link) return;
  event.preventDefault();
  if (/^(https?|mailto):/i.test(link.href)) window.open(link.href, '_blank', 'noopener,noreferrer');
}

/**
 * The email in an iframe as tall as its content, so only the dialog body scrolls. Sandboxed without
 * scripts: `allow-same-origin` only lets this page measure it and open its links in a new tab.
 * Light whatever the admin theme: the email is drawn as the mail renders it.
 *
 * Measured after load, then again whenever the email's body or the frame changes size (images,
 * Desktop / Mobile, the window). The height is rounded up from the exact layout height:
 * `scrollHeight` is a whole number, and a frame a fraction of a pixel short (zoom, Retina) would
 * scroll. `scrolling="no"`: the frame itself never shows a scrollbar.
 */
export function EmailFrame({ html, width, minWidth }: { html: string; width: number | '100%'; minWidth?: number }) {
  const frame = useRef<HTMLIFrameElement>(null);
  // Set once the email has loaded: before that the frame holds an empty page.
  const loaded = useRef(false);
  const bodyObserver = useRef<ResizeObserver | null>(null);
  const [height, setHeight] = useState(0);

  const fit = useCallback(() => {
    const iframe = frame.current;
    const root = loaded.current ? iframe?.contentDocument?.documentElement : null;
    if (!iframe || !root) return;
    // Collapsed first: `scrollHeight` is never less than the frame, so the email couldn't shrink
    // (Mobile → Desktop, a wider window). The dialog body keeps its scroll position.
    const box = iframe.parentElement;
    const top = box?.scrollTop ?? 0;
    iframe.style.height = '0px';
    const next = Math.ceil(Math.max(root.scrollHeight, root.getBoundingClientRect().height));
    iframe.style.height = `${next}px`;
    if (box) box.scrollTop = top;
    setHeight(next);
  }, []);

  // `onLoad` on the element, not a listener added in an effect: a short email can load first.
  const onLoad = () => {
    const doc = frame.current?.contentDocument;
    if (!doc?.body) return;
    loaded.current = true;
    doc.addEventListener('click', openLinks);
    bodyObserver.current?.disconnect();
    bodyObserver.current = new ResizeObserver(fit);
    bodyObserver.current.observe(doc.body);
    fit();
  };

  // The frame's width: the window, the dialog body's scrollbar, Desktop / Mobile.
  useEffect(() => {
    const iframe = frame.current;
    if (!iframe) return;
    const observer = new ResizeObserver(fit);
    observer.observe(iframe);
    window.addEventListener('resize', fit);
    return () => {
      observer.disconnect();
      window.removeEventListener('resize', fit);
      bodyObserver.current?.disconnect();
    };
  }, [fit]);

  // Desktop / Mobile: measured as soon as the new width is in.
  useEffect(fit, [fit, width, minWidth]);

  return (
    <iframe
      ref={frame}
      title={__('Email preview', 'yaymatik')}
      sandbox="allow-same-origin"
      scrolling="no"
      srcDoc={html}
      onLoad={onLoad}
      style={{ width, minWidth, height: height || undefined, colorScheme: 'light' }}
      className={cn('block max-w-full shrink-0 bg-white', !height && 'h-[60vh]')}
    />
  );
}

/** Why grids show the store's newest products instead of the order's suggestions. */
export function sampleProductsText(kinds: ProductGridKind[]): string {
  if (kinds.length > 1) return __('Sample products: no recent order has cross-sells or upsells', 'yaymatik');
  return kinds[0] === 'upsells'
    ? __('Sample products: no recent order has upsells', 'yaymatik')
    : __('Sample products: no recent order has cross-sells', 'yaymatik');
}
