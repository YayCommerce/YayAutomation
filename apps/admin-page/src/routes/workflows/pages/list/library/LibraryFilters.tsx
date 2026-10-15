import { useState, type ReactNode } from 'react';
import type { TemplateCategory, WorkflowPlugin } from '@yaymatik/types';
import { ChevronDown, X } from 'lucide-react';
import { __, _n, sprintf } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Checkbox } from '@/components/ui/checkbox';
import { PluginIcon, plugins } from '../../../shared/ui/PluginIcon';
import { categoryLabels } from '../../../templates';
import { SearchInput } from './SearchInput';

/** Rows a sidebar group shows before "Show N more". */
const SHOWN = { categories: 6, plugins: 4 };

function GroupHeading({ children }: { children: ReactNode }) {
  return (
    <h3 className="text-muted-foreground px-2 pt-2.5 pb-1.5 text-[11px] font-semibold tracking-wider uppercase first:pt-0.5">
      {children}
    </h3>
  );
}

/** "Show N more" while some rows are hidden, "Show less" once expanded; nothing when all fit. */
function ShowMore({ hidden, expanded, onToggle }: { hidden: number; expanded: boolean; onToggle: () => void }) {
  if (!expanded && !hidden) return null;
  return (
    <button
      type="button"
      onClick={onToggle}
      className="text-primary flex cursor-pointer items-center gap-1 rounded-md px-2 py-1.5 text-[13px] font-medium hover:underline"
    >
      <ChevronDown className={cn('size-3.25', expanded && 'rotate-180')} aria-hidden />
      {expanded
        ? __('Show less', 'yaymatik')
        : sprintf(
            /* translators: %d: number of hidden items */
            __('Show %d more', 'yaymatik'),
            hidden,
          )}
    </button>
  );
}

/** The first `count` rows, plus any chosen row past them, so a choice never hides. */
const firstOrChosen = <T,>(rows: T[], count: number, chosen: (row: T) => boolean) =>
  rows.filter((row, i) => i < count || chosen(row));

/**
 * The library's sidebar: Categories with counts (one at a time, or All), then Plugins with
 * checkboxes (a template shows when it uses any checked one), installed first, with a "Find a plugin" box. Long lists show
 * "Show N more". Scrolls on its own.
 */
export function LibrarySidebar({
  categories,
  counts,
  total,
  category,
  onCategory,
  pluginOptions,
  pluginFilter,
  onPluginFilter,
}: {
  categories: TemplateCategory[];
  counts: Record<TemplateCategory, number>;
  total: number;
  category: TemplateCategory | null;
  onCategory: (value: TemplateCategory | null) => void;
  pluginOptions: WorkflowPlugin[];
  pluginFilter: WorkflowPlugin[];
  onPluginFilter: (value: WorkflowPlugin[]) => void;
}) {
  const [allCategories, setAllCategories] = useState(false);
  const [allPlugins, setAllPlugins] = useState(false);
  const [query, setQuery] = useState('');

  const shownCategories = allCategories
    ? categories
    : firstOrChosen(categories, SHOWN.categories, (id) => id === category);
  // Installed first; then as listed.
  const found = pluginOptions
    .filter((id) => plugins[id].label.toLowerCase().includes(query.trim().toLowerCase()))
    .sort((a, b) => Number(plugins[b].active) - Number(plugins[a].active));
  const shownPlugins =
    allPlugins || query.trim() ? found : firstOrChosen(found, SHOWN.plugins, (id) => pluginFilter.includes(id));
  const toggle = (id: WorkflowPlugin, on: boolean) =>
    onPluginFilter(on ? [...pluginFilter, id] : pluginFilter.filter((other) => other !== id));

  const categoryRow = (value: TemplateCategory | null, label: string, count: number) => {
    const on = category === value;
    return (
      <button
        key={value ?? 'all'}
        type="button"
        aria-pressed={on}
        onClick={() => onCategory(value)}
        className={cn(
          'flex cursor-pointer items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-[13.5px]',
          on ? 'bg-primary/12 text-primary font-semibold' : 'hover:bg-accent',
        )}
      >
        {label}
        <span className={cn('text-xs tabular-nums', on ? 'text-primary' : 'text-muted-foreground')}>{count}</span>
      </button>
    );
  };

  return (
    // Beside the grid, wider on large screens; narrow screens: above it, shorter.
    <aside className="bg-muted/35 flex min-h-0 w-55 shrink-0 flex-col overflow-y-auto border-r p-3 max-md:max-h-56 max-md:w-full max-md:border-r-0 max-md:border-b xl:w-64 2xl:w-72">
      <GroupHeading>{__('Categories', 'yaymatik')}</GroupHeading>
      <div className="flex flex-col gap-0.5">
        {categoryRow(null, __('All templates', 'yaymatik'), total)}
        {shownCategories.map((id) => categoryRow(id, categoryLabels[id], counts[id]))}
      </div>
      <ShowMore
        hidden={categories.length - shownCategories.length}
        expanded={allCategories}
        onToggle={() => setAllCategories(!allCategories)}
      />

      <GroupHeading>{__('Plugins', 'yaymatik')}</GroupHeading>
      <SearchInput
        value={query}
        onChange={setQuery}
        placeholder={__('Find a plugin', 'yaymatik')}
        className="mb-1.5 h-7.5"
        inputClassName="text-[13px]"
      />
      <div className="flex flex-col">
        {shownPlugins.map((id) => (
          <label
            key={id}
            className={cn(
              'flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.25 text-[13.5px]',
              !plugins[id].active && 'text-muted-foreground',
            )}
          >
            <Checkbox checked={pluginFilter.includes(id)} onCheckedChange={(checked) => toggle(id, checked === true)} />
            <PluginIcon plugin={id} size="sm" />
            <span className="min-w-0 flex-1 truncate">{plugins[id].label}</span>
            {!plugins[id].active && <small className="text-[11.5px]">{__('Not installed', 'yaymatik')}</small>}
          </label>
        ))}
        {!found.length && (
          <p className="text-muted-foreground px-2 py-1.5 text-[13px]">{__('No plugin found.', 'yaymatik')}</p>
        )}
      </div>
      {!query.trim() && (
        <ShowMore
          hidden={found.length - shownPlugins.length}
          expanded={allPlugins}
          onToggle={() => setAllPlugins(!allPlugins)}
        />
      )}
    </aside>
  );
}

/** A chosen filter above the grid; × removes it. */
export function FilterChip({ label, onRemove }: { label: string; onRemove: () => void }) {
  return (
    <span className="bg-primary/10 text-primary inline-flex h-6.5 items-center gap-1 rounded-full pr-1.5 pl-2.5 text-[12.5px] font-medium">
      {label}
      <button
        type="button"
        onClick={onRemove}
        className="hover:bg-primary/15 flex cursor-pointer rounded-full p-0.5"
        aria-label={sprintf(
          /* translators: %s: filter name, e.g. "Post-purchase" */
          __('Remove filter %s', 'yaymatik'),
          label,
        )}
      >
        <X className="size-3" aria-hidden />
      </button>
    </span>
  );
}

/** "3 of 4 templates" above the grid. */
export const countLabel = (shown: number, total: number) =>
  sprintf(
    /* translators: 1: templates shown, 2: all templates */
    _n('%1$d of %2$d template', '%1$d of %2$d templates', total, 'yaymatik'),
    shown,
    total,
  );
