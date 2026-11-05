import { useEffect, useState } from 'react';
import { Search } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { ComboboxBadge, ComboboxCheckbox, ComboboxRemove, ComboboxTrigger } from '@/components/ui/combobox';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent } from '@/components/ui/popover';
import { useSearchLabels, useSearchOptions, type SearchType } from './searchSelect.query';

const DEBOUNCE_MS = 250;

const words: Record<SearchType, { placeholder: string; empty: string }> = {
  product: { placeholder: __('Search products', 'yaymatik'), empty: __('No products found.', 'yaymatik') },
  category: { placeholder: __('Search categories', 'yaymatik'), empty: __('No categories found.', 'yaymatik') },
  coupon: { placeholder: __('Search coupons', 'yaymatik'), empty: __('No coupons found.', 'yaymatik') },
};

/** Any number of values, shown like `MultiSelect`, picked from a list the server searches and pages. */
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

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <ComboboxTrigger id={id} aria-expanded={open} aria-invalid={invalid} className="w-full min-w-0">
        <span className="flex flex-wrap gap-1">
          {value.length ? (
            value.map((option) => (
              <ComboboxBadge key={option}>
                {labelOf(option)}
                <ComboboxRemove onRemove={() => toggle(option)} />
              </ComboboxBadge>
            ))
          ) : (
            <span className="text-muted-foreground px-1">{words[type].placeholder}</span>
          )}
        </span>
        <Search className="text-muted-foreground size-4 shrink-0" aria-hidden />
      </ComboboxTrigger>
      <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start">
        {/* The server searches, so the list doesn't filter what it gets. */}
        <Command shouldFilter={false}>
          <CommandInput placeholder={words[type].placeholder} value={typed} onValueChange={setTyped} />
          <CommandList>
            {!search.isPending && !search.isError && <CommandEmpty>{words[type].empty}</CommandEmpty>}
            {search.isError && (
              <div className="flex flex-col items-center gap-1 p-3 text-sm">
                {__('Couldn’t load the list.', 'yaymatik')}
                <Button variant="ghost" size="sm" onClick={() => void search.refetch()}>
                  {__('Try again', 'yaymatik')}
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
            {/* translators: %d: how many values are chosen */}
            <span>{sprintf(__('%d selected', 'yaymatik'), value.length)}</span>
            {search.hasNextPage && (
              <Button
                variant="link"
                size="sm"
                className="h-auto p-0 text-xs"
                disabled={search.isFetchingNextPage}
                onClick={() => void search.fetchNextPage()}
              >
                {__('Load more', 'yaymatik')}
              </Button>
            )}
          </div>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
