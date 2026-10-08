import { useState, type ReactNode } from 'react';
import type { TemplateCategory, WorkflowPlugin } from '@yay-automation/types';
import { ChevronDown, Search } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Checkbox } from '@/components/ui/checkbox';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { plugins } from '../../../shared/ui/PluginIcon';
import { categoryLabels } from '../../../templates';

/**
 * A filter's button: dashed while nothing is chosen, solid with the choice
 * ("Categories: Post-purchase") once something is.
 */
function FilterPill({ label, value, children }: { label: string; value: string | null; children: ReactNode }) {
  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          className={cn(
            'hover:bg-accent flex cursor-pointer items-center gap-1 rounded-full border px-3 py-1 text-sm font-medium transition-colors',
            value ? 'border-primary/40 bg-primary/5' : 'border-dashed',
          )}
        >
          {value ? `${label}: ${value}` : label}
          <ChevronDown className="size-4" aria-hidden />
        </button>
      </PopoverTrigger>
      {/* Above the library dialog, like Select's menu. */}
      <PopoverContent align="start" className="z-100005 w-72 p-0">
        {children}
      </PopoverContent>
    </Popover>
  );
}

/** Names of the first two plugins chosen, then "+N" for the rest (the popover lists them all). */
const MAX_NAMED = 2;
function chosenLabel(value: WorkflowPlugin[]) {
  if (!value.length) return null;
  const names = value
    .slice(0, MAX_NAMED)
    .map((id) => plugins[id].label)
    .join(', ');
  return value.length > MAX_NAMED ? `${names} +${value.length - MAX_NAMED}` : names;
}

/** Plugins the library's templates use; picking several shows templates that use any of them. */
export function PluginsFilter({
  options,
  value,
  onChange,
}: {
  options: WorkflowPlugin[];
  value: WorkflowPlugin[];
  onChange: (value: WorkflowPlugin[]) => void;
}) {
  const [query, setQuery] = useState('');
  const shown = options.filter((id) => plugins[id].label.toLowerCase().includes(query.trim().toLowerCase()));
  const groups = [
    { title: __('Installed plugins', 'yay-automation'), ids: shown.filter((id) => plugins[id].active) },
    { title: __('Not installed', 'yay-automation'), ids: shown.filter((id) => !plugins[id].active) },
  ].filter((group) => group.ids.length);
  const toggle = (id: WorkflowPlugin, on: boolean) =>
    onChange(on ? [...value, id] : value.filter((other) => other !== id));

  return (
    <FilterPill label={__('Plugins', 'yay-automation')} value={chosenLabel(value)}>
      <div className="border-b p-2">
        <InputGroup>
          <InputGroupAddon>
            <Search />
          </InputGroupAddon>
          <InputGroupInput
            value={query}
            placeholder={__('Search', 'yay-automation')}
            onChange={(event) => setQuery(event.target.value)}
          />
        </InputGroup>
      </div>
      <div className="flex max-h-72 flex-col gap-3 overflow-y-auto p-3">
        {groups.map((group) => (
          <section key={group.title} className="flex flex-col gap-2">
            <h3 className="text-sm font-semibold">{group.title}</h3>
            {group.ids.map((id) => (
              <Label key={id} className="flex cursor-pointer items-center gap-2 font-normal">
                <Checkbox checked={value.includes(id)} onCheckedChange={(checked) => toggle(id, checked === true)} />
                {plugins[id].label}
              </Label>
            ))}
          </section>
        ))}
        {!groups.length && <p className="text-muted-foreground text-sm">{__('No plugin found.', 'yay-automation')}</p>}
      </div>
    </FilterPill>
  );
}

/** Radio value for "no category chosen". */
const ALL = 'all';

/** One category at a time, or All. */
export function CategoryFilter({
  options,
  value,
  onChange,
}: {
  options: TemplateCategory[];
  value: TemplateCategory | null;
  onChange: (value: TemplateCategory | null) => void;
}) {
  return (
    <FilterPill label={__('Categories', 'yay-automation')} value={value && categoryLabels[value]}>
      <RadioGroup
        value={value ?? ALL}
        onValueChange={(next) => onChange(next === ALL ? null : (next as TemplateCategory))}
        className="flex flex-col gap-2 p-3"
      >
        <Label className="flex cursor-pointer items-center gap-2 font-normal">
          <RadioGroupItem value={ALL} />
          {__('All', 'yay-automation')}
        </Label>
        {options.map((id) => (
          <Label key={id} className="flex cursor-pointer items-center gap-2 font-normal">
            <RadioGroupItem value={id} />
            {categoryLabels[id]}
          </Label>
        ))}
      </RadioGroup>
    </FilterPill>
  );
}
