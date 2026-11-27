import { forwardRef, useRef, useState, type ComponentProps } from 'react';
import { __ } from '@wordpress/i18n';

import { Command, CommandGroup, CommandItem, CommandList } from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { useCustomerMetaKeys } from './searchSelect.query';

/** Nothing is highlighted until an arrow key moves into the list, so Enter keeps what was typed. */
const NONE = '\u0000';

/**
 * A custom field name, with the names other workflows use listed under it ("Used in your workflows").
 * Any other name can be typed; arrow keys move through the list, Enter picks, Escape closes.
 */
export const KeySuggestInput = forwardRef<
  HTMLInputElement,
  Omit<ComponentProps<typeof Input>, 'value' | 'onChange'> & { value: string; onChange: (value: string) => void }
>(function KeySuggestInput({ value, onChange, onFocus, onKeyDown, className, ...props }, ref) {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(NONE);
  const moved = useRef(false);
  const anchor = useRef<HTMLDivElement>(null);
  const { data } = useCustomerMetaKeys(true);
  const typed = value.trim().toLowerCase();
  const keys = (data?.keys ?? []).filter((key) => key !== value && key.toLowerCase().includes(typed));
  const shown = open && keys.length > 0;
  const close = () => {
    setOpen(false);
    moved.current = false;
    setActive(NONE);
  };

  return (
    <Command
      shouldFilter={false}
      loop
      value={active}
      // cmdk highlights the first row on its own; only a move with the arrow keys counts.
      onValueChange={(next) => moved.current && setActive(next)}
      className="overflow-visible bg-transparent"
    >
      <Popover open={shown} onOpenChange={(next) => (next ? setOpen(true) : close())}>
        <PopoverAnchor asChild>
          <div ref={anchor}>
            <Input
              ref={ref}
              role="combobox"
              aria-expanded={shown}
              aria-autocomplete="list"
              autoComplete="off"
              className={className}
              value={value}
              onChange={(event) => {
                onChange(event.target.value);
                moved.current = false;
                setActive(NONE);
                setOpen(true);
              }}
              onFocus={(event) => {
                setOpen(true);
                onFocus?.(event);
              }}
              onKeyDown={(event) => {
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                  moved.current = true;
                  setOpen(true);
                } else if (event.key === 'Escape') {
                  close();
                } else if (event.key === 'Enter' && !moved.current) {
                  // Keeps the typed name: the list's own Enter would pick its first row.
                  event.stopPropagation();
                  close();
                }
                onKeyDown?.(event);
              }}
              {...props}
            />
          </div>
        </PopoverAnchor>
        <PopoverContent
          className="w-(--radix-popover-trigger-width) min-w-48 p-0"
          align="start"
          onOpenAutoFocus={(event) => event.preventDefault()}
          onInteractOutside={(event) => {
            if (anchor.current?.contains(event.target as Node)) event.preventDefault();
          }}
        >
          <CommandList>
            <CommandGroup heading={__('Used in your workflows', 'yaymatik-automation-for-woocommerce')}>
              {keys.map((key) => (
                <CommandItem
                  key={key}
                  value={key}
                  className="font-mono text-xs"
                  onSelect={() => {
                    onChange(key);
                    close();
                  }}
                >
                  {key}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </PopoverContent>
      </Popover>
    </Command>
  );
});
