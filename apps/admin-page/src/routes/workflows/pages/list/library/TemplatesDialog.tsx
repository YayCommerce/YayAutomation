import { useMemo, useState } from 'react';
import type { TemplateCategory, WorkflowPlugin, WorkflowTemplate } from '@yay-automation/types';
import { Search, SearchX } from 'lucide-react';
import { Link } from 'react-router';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { LibraryNavState } from '../../../shared/nav/library.nav';
import { PluginIcon } from '../../../shared/ui/PluginIcon';
import { comingSoonLabel } from '../../../steps/core/pending';
import { categoryLabels, isComingSoon, pluginsOf, templates } from '../../../templates';
import { TemplatesHeroIllustration } from '../WorkflowIllustrations';
import { CategoryFilter, PluginsFilter } from './LibraryFilters';

type SortKey = 'popular' | 'newest' | 'name';

const sortOptions: { value: SortKey; label: string }[] = [
  { value: 'popular', label: __('Most popular', 'yay-automation') },
  { value: 'newest', label: __('Newest', 'yay-automation') },
  { value: 'name', label: __('Name (A–Z)', 'yay-automation') },
];

const compare: Record<SortKey, (a: WorkflowTemplate, b: WorkflowTemplate) => number> = {
  popular: (a, b) => b.popularity - a.popularity,
  newest: (a, b) => b.createdAt.localeCompare(a.createdAt),
  name: (a, b) => a.name.localeCompare(b.name),
};

export function TemplatesDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        // Fixed height (capped on tall screens) so filtering doesn't resize and re-center the dialog.
        // Close icon sits on the primary hero, so it takes the hero's foreground color.
        className="*:data-[slot=dialog-close]:text-primary-foreground/80 *:data-[slot=dialog-close]:hover:text-primary-foreground flex h-[min(calc(100vh-4rem),60rem)] flex-col gap-0 p-0 sm:max-w-6xl"
      >
        <TemplateLibrary />
      </DialogContent>
    </Dialog>
  );
}

/** Categories that have templates, in `categoryLabels` order. */
const categories = (Object.keys(categoryLabels) as TemplateCategory[]).filter((category) =>
  templates.some((t) => t.category === category),
);

/** Plugins the templates use. */
const templatePlugins = [...new Set(templates.flatMap((t) => pluginsOf(t.graph)))];

/** Split out so search/sort/filter state resets each time the dialog opens. */
function TemplateLibrary() {
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<SortKey>('popular');
  const [category, setCategory] = useState<TemplateCategory | null>(null);
  const [pluginFilter, setPluginFilter] = useState<WorkflowPlugin[]>([]);

  const visible = useMemo(() => {
    const query = search.trim().toLowerCase();
    return templates
      .filter((t) => !category || t.category === category)
      .filter((t) => !pluginFilter.length || pluginsOf(t.graph).some((plugin) => pluginFilter.includes(plugin)))
      .filter(
        (t) =>
          !query ||
          [t.name, t.description, categoryLabels[t.category]].some((text) => text.toLowerCase().includes(query)),
      )
      .sort(compare[sort]);
  }, [search, sort, category, pluginFilter]);

  return (
    <>
      <div className="bg-primary text-primary-foreground flex items-center justify-between gap-6 p-8 sm:px-10">
        <div className="max-w-xl">
          <DialogTitle className="text-primary-foreground text-3xl font-semibold tracking-tight">
            {__('Template Library', 'yay-automation')}
          </DialogTitle>
          <DialogDescription className="text-primary-foreground/85 mt-2 text-base">
            {__(
              'Start from a pre-built workflow for your WooCommerce store. Search by what you want to automate.',
              'yay-automation',
            )}
          </DialogDescription>
        </div>
        <TemplatesHeroIllustration className="shrink-0 max-md:hidden" />
      </div>

      <div className="bg-background flex items-center gap-3 border-b px-6 py-3">
        <InputGroup className="flex-1">
          <InputGroupAddon>
            <Search />
          </InputGroupAddon>
          <InputGroupInput
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={__('Search templates', 'yay-automation')}
            aria-label={__('Search templates', 'yay-automation')}
          />
        </InputGroup>
        <span className="text-muted-foreground text-sm whitespace-nowrap max-sm:hidden">
          {__('Sort by:', 'yay-automation')}
        </span>
        <Select value={sort} onValueChange={(value) => setSort(value as SortKey)}>
          <SelectTrigger className="w-40" aria-label={__('Sort templates', 'yay-automation')}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {sortOptions.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="bg-background flex flex-wrap items-center gap-2 border-b px-6 py-2.5">
        <PluginsFilter options={templatePlugins} value={pluginFilter} onChange={setPluginFilter} />
        <CategoryFilter options={categories} value={category} onChange={setCategory} />
        {(category || pluginFilter.length > 0) && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              setCategory(null);
              setPluginFilter([]);
            }}
          >
            {__('Clear all', 'yay-automation')}
          </Button>
        )}
      </div>

      {/* `relative` keeps absolutely positioned descendants (sr-only labels) inside this scroll box. */}
      <div className="relative min-h-0 flex-1 overflow-y-auto p-6">
        <p className="text-muted-foreground mb-4 text-sm">
          {
            /* translators: %d: number of templates shown */
            sprintf(_n('%d template', '%d templates', visible.length, 'yay-automation'), visible.length)
          }
        </p>
        {visible.length === 0 ? (
          <Empty className="py-12">
            <EmptyHeader>
              <EmptyMedia variant="icon">
                <SearchX />
              </EmptyMedia>
              <EmptyTitle>{__('No templates found', 'yay-automation')}</EmptyTitle>
              <EmptyDescription>{__('Try a different search term or filter.', 'yay-automation')}</EmptyDescription>
            </EmptyHeader>
          </Empty>
        ) : (
          <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {visible.map((template) => (
              <TemplateCard key={template.id} template={template} />
            ))}
          </div>
        )}
      </div>
    </>
  );
}

function TemplateCard({ template }: { template: WorkflowTemplate }) {
  return (
    <Link
      to={`/workflows/templates/${template.id}`}
      state={{ fromLibrary: true } satisfies LibraryNavState}
      className="bg-card text-card-foreground hover:border-primary focus-visible:border-primary flex min-h-48 flex-col rounded-lg border p-5 shadow-xs transition-colors outline-none hover:shadow-sm"
    >
      <div className="flex items-start justify-between gap-2">
        <span className="font-semibold">{template.name}</span>
        {isComingSoon(template.graph) && <Badge variant="dimmed">{comingSoonLabel}</Badge>}
      </div>
      <p className="text-muted-foreground mt-2 line-clamp-3 text-sm">{template.description}</p>
      <div className="mt-auto flex items-center justify-between gap-3 pt-6">
        <span className="text-muted-foreground text-sm">{categoryLabels[template.category]}</span>
        <div className="flex gap-1.5">
          {pluginsOf(template.graph).map((plugin) => (
            <PluginIcon key={plugin} plugin={plugin} />
          ))}
        </div>
      </div>
    </Link>
  );
}
