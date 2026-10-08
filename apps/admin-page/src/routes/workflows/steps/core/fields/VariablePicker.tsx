import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { Slot } from '@radix-ui/react-slot';
import { ArrowLeft, ChevronRight, Search, Zap } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { dataItems, type DataField } from '../data';
import { comingSoonLabel, isPendingField } from '../pending';
import { useVariableScope } from './variableScope';

const MODAL_WIDTH = 512;
const MODAL_HEIGHT = 480;

/**
 * Put on the element a picker edits (a criteria box, a value input, a form field) along with
 * `data-spotlight`: while the picker is open it stays bright above the dimmed step panel.
 */
export const spotlightTarget =
  'data-[spotlit=on]:bg-card data-[spotlit=on]:ring-card data-[spotlit=on]:relative data-[spotlit=on]:z-20 data-[spotlit=on]:rounded-lg data-[spotlit=on]:ring-8';

const matches = (texts: string[], query: string) =>
  texts.some((text) => text.toLowerCase().includes(query.trim().toLowerCase()));

/**
 * "Add a variable", like Shopify Flow: a modal beside the step panel, level with the element being
 * edited (the nearest `data-spotlight` around `children`), which stays bright while the rest dims.
 * The trigger first, then the data items the step can read (key, description, `>`), each opening its
 * fields. Searching lists matching fields across items.
 */
export function VariablePicker({
  conditionalOnly = false,
  filter,
  types,
  autoOpen = false,
  onSelect,
  onDismiss,
  children,
}: {
  /** Leave out display-only fields. */
  conditionalOnly?: boolean;
  /** Only fields it accepts (e.g. the same type as the other side of a comparison). */
  filter?: (field: DataField) => boolean;
  /** "Types: …" line under the search, when `filter` narrows by type. */
  types?: string;
  /** Open as soon as it mounts (a criteria just added). */
  autoOpen?: boolean;
  onSelect: (key: string) => void;
  /** Closed without a pick. */
  onDismiss?: () => void;
  /** The element that opens it. */
  children: ReactNode;
}) {
  const { available, trigger } = useVariableScope();
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState<CSSProperties>();
  const [itemId, setItemId] = useState<string | null>(null);
  const [query, setQuery] = useState('');

  // Built only while open: a criteria box holds several closed pickers.
  const items = (open ? dataItems : [])
    .filter((item) => available.includes(item.id))
    .map((item) => ({
      ...item,
      fields: item.fields.filter((field) => (!conditionalOnly || field.conditional) && (!filter || filter(field))),
    }))
    .filter((item) => item.fields.length);
  const item = items.find((i) => i.id === itemId);

  // Focus goes back to the opener on close: the dialog has no DialogTrigger to return it to.
  const opener = useRef<HTMLElement | null>(null);
  // Marked while open: `data-dimmed` on the panel (it lifts and dims) and `data-spotlit` on the edited element.
  const marked = useRef<HTMLElement[]>([]);

  // Beside the step panel, level with the edited element, kept on screen; centered without a panel.
  const openFrom = (element: HTMLElement) => {
    opener.current = element;
    const panel = element.closest<HTMLElement>('[data-step-panel]');
    const target = element.closest<HTMLElement>('[data-spotlight]') ?? element;
    if (panel) {
      const { right } = panel.getBoundingClientRect();
      const { top } = target.getBoundingClientRect();
      setPosition({
        left: Math.max(16, Math.min(right + 12, window.innerWidth - MODAL_WIDTH - 16)),
        top: Math.max(16, Math.min(top, window.innerHeight - MODAL_HEIGHT - 16)),
        translate: 'none',
      });
      panel.dataset.dimmed = 'on';
      target.dataset.spotlit = 'on';
      marked.current = [panel, target];
    } else {
      setPosition(undefined);
    }
    setItemId(null);
    setQuery('');
    setOpen(true);
  };
  const close = (picked: boolean) => {
    setOpen(false);
    for (const element of marked.current) {
      delete element.dataset.dimmed;
      delete element.dataset.spotlit;
    }
    marked.current = [];
    if (!picked) onDismiss?.();
  };
  const pick = (key: string) => {
    onSelect(key);
    close(true);
  };

  const slot = useRef<HTMLElement>(null);
  useEffect(() => {
    if (autoOpen && slot.current) openFrom(slot.current);
    // Only on mount: `autoOpen` means "opened by the action that created this".
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  let list: ReactNode;
  if (query.trim()) {
    const found = items.flatMap((i) => i.fields.filter((field) => matches([field.label, field.key], query)));
    list = found.length ? (
      found.map((field) => <FieldRow key={field.key} field={field} onPick={pick} />)
    ) : (
      <p className="text-muted-foreground p-4 text-sm">{__('No variable found.', 'yay-automation')}</p>
    );
  } else if (item) {
    list = (
      <>
        <div className="flex items-center gap-2 border-b px-2 py-1.5">
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={__('Back to variables', 'yay-automation')}
            onClick={() => setItemId(null)}
          >
            <ArrowLeft />
          </Button>
          <span className="font-mono text-sm">{item.id}</span>
        </div>
        {item.fields.map((field) => (
          <FieldRow key={field.key} field={field} onPick={pick} />
        ))}
      </>
    );
  } else {
    list = items.map((i) => (
      <button
        key={i.id}
        type="button"
        onClick={() => setItemId(i.id)}
        className="hover:bg-accent flex w-full cursor-pointer items-center gap-3 border-b px-4 py-3 text-left"
      >
        <span className="min-w-0 flex-1">
          <span className="block font-mono text-sm">{i.id}</span>
          <span className="text-muted-foreground line-clamp-2 text-xs">{i.description}</span>
        </span>
        <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden />
      </button>
    ));
  }

  return (
    <>
      <Slot ref={slot} onClick={(event) => openFrom(event.currentTarget as HTMLElement)}>
        {children}
      </Slot>
      <Dialog open={open} onOpenChange={(next) => !next && close(false)}>
        {/* `nokey`: Backspace in the search mustn't reach ReactFlow's delete-selected-step shortcut. */}
        <DialogContent
          style={{ ...position, width: MODAL_WIDTH, height: MODAL_HEIGHT }}
          // Above the lifted step panel (z-100002), which sits above the overlay (z-100001).
          className="nokey z-100003 flex max-h-[calc(100vh-2rem)] max-w-[calc(100vw-2rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-[calc(100vw-2rem)]"
          onCloseAutoFocus={(event) => {
            event.preventDefault();
            opener.current?.focus();
          }}
        >
          <div className="bg-muted/50 border-b px-4 py-3">
            <DialogTitle className="text-sm font-semibold">{__('Add a variable', 'yay-automation')}</DialogTitle>
            <DialogDescription className="sr-only">
              {__('Pick data from the workflow to use here.', 'yay-automation')}
            </DialogDescription>
          </div>
          <div className="border-b p-2">
            <InputGroup>
              <InputGroupAddon>
                <Search />
              </InputGroupAddon>
              <InputGroupInput
                autoFocus
                value={query}
                placeholder={__('Search by variable name or description', 'yay-automation')}
                onChange={(event) => setQuery(event.target.value)}
              />
            </InputGroup>
            {types && (
              <p className="bg-muted text-muted-foreground mt-2 w-fit rounded-md px-2 py-0.5 text-xs">
                {
                  /* translators: %s: variable types, e.g. "Number" */
                  sprintf(__('Types: %s', 'yay-automation'), types)
                }
              </p>
            )}
          </div>
          {trigger && (
            <div className="bg-muted/50 flex items-center gap-2 border-b px-4 py-2 text-sm">
              <Zap className="text-muted-foreground size-4" aria-hidden />
              <span className="min-w-0 flex-1 truncate">{trigger}</span>
              <Badge variant="dimmed">{__('Trigger', 'yay-automation')}</Badge>
            </div>
          )}
          <div className="min-h-0 flex-1 overflow-y-auto">{list}</div>
        </DialogContent>
      </Dialog>
    </>
  );
}

/** A field whose PHP side isn't built yet shows "Coming soon" and can't be picked. */
function FieldRow({ field, onPick }: { field: DataField; onPick: (key: string) => void }) {
  const comingSoon = isPendingField(field.key);
  return (
    <button
      type="button"
      onClick={() => onPick(field.key)}
      disabled={comingSoon}
      className={cn(
        'flex w-full flex-col border-b px-4 py-2.5 text-left',
        comingSoon ? 'cursor-not-allowed opacity-50' : 'hover:bg-accent cursor-pointer',
      )}
    >
      <span className="flex items-center gap-2 text-sm">
        {field.label}
        {comingSoon && <Badge variant="dimmed">{comingSoonLabel}</Badge>}
      </span>
      <span className="text-muted-foreground font-mono text-xs">{field.key}</span>
    </button>
  );
}
