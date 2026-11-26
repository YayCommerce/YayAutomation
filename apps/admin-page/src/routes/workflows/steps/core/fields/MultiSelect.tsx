import { useState } from 'react';
import { __ } from '@wordpress/i18n';

import type { OptionMeta } from '@/types/localize';
import {
  ComboboxBadge,
  ComboboxCheckbox,
  ComboboxIcon,
  ComboboxRemove,
  ComboboxTrigger,
} from '@/components/ui/combobox';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent } from '@/components/ui/popover';

/** Any number of `options`, shown as removable badges, picked from a searchable list. */
export function MultiSelect({
  id,
  options,
  value,
  onChange,
  placeholder,
  invalid,
}: {
  id: string;
  options: OptionMeta[];
  value: string[];
  onChange: (value: string[]) => void;
  placeholder: string;
  invalid: boolean;
}) {
  const [open, setOpen] = useState(false);
  const toggle = (option: string) =>
    onChange(value.includes(option) ? value.filter((v) => v !== option) : [...value, option]);
  const labelOf = (option: string) => options.find((o) => o.value === option)?.label ?? option;

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
            <span className="text-muted-foreground px-1">{placeholder}</span>
          )}
        </span>
        <ComboboxIcon />
      </ComboboxTrigger>
      <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start">
        <Command>
          <CommandInput placeholder={__('Search', 'yaymatik-automation-for-woocommerce')} />
          <CommandList>
            <CommandEmpty>{__('Nothing found.', 'yaymatik-automation-for-woocommerce')}</CommandEmpty>
            <CommandGroup>
              {options.map((option) => (
                <CommandItem key={option.value} value={option.label} onSelect={() => toggle(option.value)}>
                  <ComboboxCheckbox selected={value.includes(option.value)} />
                  {option.label}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
