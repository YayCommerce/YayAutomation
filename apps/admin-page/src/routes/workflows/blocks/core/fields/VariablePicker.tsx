import { useState, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { dataItems } from '../data';

/**
 * Searchable list of variables grouped by data item, opened from `children` (the trigger element).
 * Lists only the items in `available`; `conditionalOnly` leaves out display-only fields.
 */
export function VariablePicker({
  available,
  conditionalOnly = false,
  onSelect,
  children,
}: {
  available: string[];
  conditionalOnly?: boolean;
  onSelect: (key: string) => void;
  children: ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const groups = dataItems
    .filter((item) => available.includes(item.id))
    .map((item) => ({ ...item, fields: item.fields.filter((field) => !conditionalOnly || field.conditional) }))
    .filter((item) => item.fields.length);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>{children}</PopoverTrigger>
      <PopoverContent className="w-72 p-0" align="start">
        <Command>
          <CommandInput placeholder={__('Search variables', 'yay-automation')} />
          <CommandList>
            <CommandEmpty>{__('No variable found.', 'yay-automation')}</CommandEmpty>
            {groups.map((item) => (
              <CommandGroup key={item.id} heading={item.label}>
                {item.fields.map((field) => (
                  <CommandItem
                    key={field.key}
                    value={`${field.label} ${field.key}`}
                    onSelect={() => {
                      onSelect(field.key);
                      setOpen(false);
                    }}
                  >
                    <span className="flex min-w-0 flex-col">
                      <span>{field.label}</span>
                      <span className="text-muted-foreground truncate text-xs">{field.key}</span>
                    </span>
                  </CommandItem>
                ))}
              </CommandGroup>
            ))}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
