import { useEffect, useRef, useState } from 'react';
import { Search } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { ComboboxBadge, ComboboxCheckbox, ComboboxRemove } from '@/components/ui/combobox';
import { Command, CommandEmpty, CommandGroup, CommandItem, CommandList } from '@/components/ui/command';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { useSearchLabels, useSearchOptions, type SearchType } from './searchSelect.query';

const DEBOUNCE_MS = 250;

const words: Record<SearchType, { placeholder: string; empty: string }> = {
  product: {
    placeholder: __('Search products', 'yaymatik-automation-for-woocommerce'),
    empty: __('No products found.', 'yaymatik-automation-for-woocommerce'),
  },
  category: {
    placeholder: __('Search categories', 'yaymatik-automation-for-woocommerce'),
    empty: __('No categories found.', 'yaymatik-automation-for-woocommerce'),
  },
  coupon: {
    placeholder: __('Search coupons', 'yaymatik-automation-for-woocommerce'),
    empty: __('No coupons found.', 'yaymatik-automation-for-woocommerce'),
  },
};

/** Any number of values as chips, picked from a list the server searches and pages: typed in the field beside the chips, the list opens below. */
export function SearchSelect({
  id,
  type,
  value,
  onChange,
  invalid,
}: {
  id: string;
  type: SearchType;
  value: string[];
  onChange: (value: string[]) => void;
  invalid: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [typed, setTyped] = useState('');
  const [term, setTerm] = useState('');
  useEffect(() => {
    const timer = setTimeout(() => setTerm(typed.trim()), DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [typed]);

  const search = useSearchOptions(type, term, open);
  const saved = useSearchLabels(type, value);
  // By value: a new item between two pages can show a row twice.
  const options = [
    ...new Map(search.data?.pages.flatMap((page) => page.items).map((item) => [item.value, item])).values(),
  ];
  const toggle = (option: string) =>
    onChange(value.includes(option) ? value.filter((v) => v !== option) : [...value, option]);

  /** A value the server says it no longer has (a deleted product) reads by its id; a failed request isn't an answer. */
  const labelOf = (option: string) => {
    const known = [...(saved.data ?? []), ...options].find((item) => item.value === option)?.label;
    if (known) return known;
    if (!saved.isSuccess || saved.isPlaceholderData) return '…';
    return type === 'coupon' ? option : `#${option}`;
  };

  const anchor = useRef<HTMLDivElement>(null);
  const input = useRef<HTMLInputElement>(null);

  return (
    // The list sits in a portal; cmdk's keys still reach it from the input inside this root.
    <Command shouldFilter={false} loop className="overflow-visible bg-transparent">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverAnchor asChild>
          <InputGroup
            ref={anchor}
            aria-invalid={invalid}
            className="h-auto min-h-9 cursor-text"
            onClick={() => input.current?.focus()}
          >
            <div className="my-1 flex flex-1 flex-wrap items-center gap-1 ps-1">
              {value.map((option) => (
                <ComboboxBadge key={option}>
                  {labelOf(option)}
                  <ComboboxRemove className="cursor-pointer" onRemove={() => toggle(option)} />
                </ComboboxBadge>
              ))}
              <InputGroupInput
                ref={input}
                id={id}
                role="combobox"
                aria-expanded={open}
                aria-autocomplete="list"
                aria-invalid={invalid}
                className="min-w-20 pl-1"
                placeholder={value.length ? '' : words[type].placeholder}
                value={typed}
                onChange={(event) => {
                  setTyped(event.target.value);
                  setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onKeyDown={(event) => {
                  if (event.key === 'Escape') setOpen(false);
                  else if (event.key === 'Backspace' && !typed && value.length) toggle(value[value.length - 1]);
                  else if (!open && event.key === 'ArrowDown') setOpen(true);
                }}
              />
            </div>
            <InputGroupAddon align="inline-end">
              <Search aria-hidden />
            </InputGroupAddon>
          </InputGroup>
        </PopoverAnchor>
        <PopoverContent
          className="w-(--radix-popover-trigger-width) p-0"
          align="start"
          // Typing stays in the field; a click on the field isn't a click outside.
          onOpenAutoFocus={(event) => event.preventDefault()}
          onInteractOutside={(event) => {
            if (anchor.current?.contains(event.target as Node)) event.preventDefault();
          }}
        >
          {/* The server searches, so the list doesn't filter what it gets. */}
          <CommandList>
            {!search.isPending && !search.isError && <CommandEmpty>{words[type].empty}</CommandEmpty>}
            {search.isError && (
              <div className="flex flex-col items-center gap-1 p-3 text-sm">
                {__('Couldn’t load the list.', 'yaymatik-automation-for-woocommerce')}
                <Button variant="ghost" size="sm" onClick={() => void search.refetch()}>
                  {__('Try again', 'yaymatik-automation-for-woocommerce')}
                </Button>
              </div>
            )}
            <CommandGroup>
              {options.map((option) => (
                <CommandItem key={option.value} value={option.value} onSelect={() => toggle(option.value)}>
                  <ComboboxCheckbox selected={value.includes(option.value)} />
                  <span className="min-w-0 flex-1 truncate">{option.label}</span>
                  {option.hint && <small className="text-muted-foreground shrink-0 text-xs">{option.hint}</small>}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
          <div className="text-muted-foreground flex items-center justify-between gap-2 border-t px-3 py-1.5 text-xs">
            <span>
              {sprintf(
                /* translators: %d: number of selected items */ __(
                  '%d selected',
                  'yaymatik-automation-for-woocommerce',
                ),
                value.length,
              )}
            </span>
            {search.hasNextPage && (
              <Button
                variant="link"
                size="sm"
                className="h-auto p-0 text-xs"
                disabled={search.isFetchingNextPage}
                onClick={() => void search.fetchNextPage()}
              >
                {__('Load more', 'yaymatik-automation-for-woocommerce')}
              </Button>
            )}
          </div>
        </PopoverContent>
      </Popover>
    </Command>
  );
}
