import { useMemo, useState } from 'react';
import type { TemplateCategory, WorkflowPlugin, WorkflowTemplate } from '@yay-automation/types';
import { Search, SearchX } from 'lucide-react';
import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { stepsInOrder } from '../../../model/graph';
import type { LibraryNavState } from '../../../shared/nav/library.nav';
import { ComingSoonChip } from '../../../shared/ui/ComingSoonChip';
import { PluginIcon, plugins } from '../../../shared/ui/PluginIcon';
import { stepOf } from '../../../steps';
import { StepTile } from '../../../steps/core/StepTile';
import { categoryLabels, isComingSoon, pluginsOf, templates } from '../../../templates';
import { usesAnyOf } from './filter';
import { countLabel, FilterChip, LibrarySidebar } from './LibraryFilters';

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
        className="flex h-[min(calc(100vh-4rem),60rem)] flex-col gap-0 p-0 sm:max-w-6xl"
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

/** What each card draws, worked out once: templates never change while the page is open. */
const cardData = new Map(
  templates.map((t) => {
    const steps = stepsInOrder(t.graph);
    // A strip line joins two tiles only when one leads to the other, so branches don't read as a chain.
    const linked = steps.map(
      (node, i) => i > 0 && t.graph.edges.some((edge) => edge.source === steps[i - 1].id && edge.target === node.id),
    );
    return [t.id, { steps, linked, plugins: pluginsOf(t.graph), comingSoon: isComingSoon(t.graph) }] as const;
  }),
);

/** Plugins the templates use. */
const templatePlugins = [...new Set(templates.flatMap((t) => cardData.get(t.id)!.plugins))];

/** Split out so search/sort/filter state resets each time the dialog opens. */
function TemplateLibrary() {
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<SortKey>('popular');
  const [category, setCategory] = useState<TemplateCategory | null>(null);
  const [pluginFilter, setPluginFilter] = useState<WorkflowPlugin[]>([]);

  // Search and plugins first: the category counts follow them, so a count never promises what
  // the grid won't show.
  const matching = useMemo(() => {
    const query = search.trim().toLowerCase();
    return templates
      .filter((t) => usesAnyOf(cardData.get(t.id)!.plugins, pluginFilter))
      .filter(
        (t) =>
          !query ||
          [t.name, t.description, categoryLabels[t.category]].some((text) => text.toLowerCase().includes(query)),
      );
  }, [search, pluginFilter]);
  const counts = Object.fromEntries(
    categories.map((id) => [id, matching.filter((t) => t.category === id).length]),
  ) as Record<TemplateCategory, number>;
  const visible = matching.filter((t) => !category || t.category === category).sort(compare[sort]);

  const filtered = !!category || pluginFilter.length > 0;

  return (
    <>
      {/* pr-14 keeps the search clear of the dialog's close button. */}
      <div className="flex flex-wrap items-center gap-4 border-b py-4 pr-14 pl-5">
        <div className="min-w-0 flex-1">
          <DialogTitle className="text-lg font-semibold">{__('Template Library', 'yay-automation')}</DialogTitle>
          <DialogDescription className="text-[13px]">
            {__('Ready-made workflows for WooCommerce. Pick one, review it, then activate.', 'yay-automation')}
          </DialogDescription>
        </div>
        <InputGroup className="w-70 max-sm:w-full">
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
      </div>

      <div className="flex min-h-0 flex-1 max-md:flex-col">
        <LibrarySidebar
          categories={categories}
          counts={counts}
          total={matching.length}
          category={category}
          onCategory={setCategory}
          pluginOptions={templatePlugins}
          pluginFilter={pluginFilter}
          onPluginFilter={setPluginFilter}
        />

        {/* `relative` keeps absolutely positioned descendants (sr-only labels) inside this scroll box. */}
        <div className="relative flex min-w-0 flex-1 flex-col gap-3 overflow-y-auto px-5 py-4">
          <div className="flex items-center justify-between gap-3">
            <div className="flex flex-wrap items-center gap-1.5">
              <span className="text-muted-foreground mr-1 text-[13.5px]">
                {countLabel(visible.length, templates.length)}
              </span>
              {category && <FilterChip label={categoryLabels[category]} onRemove={() => setCategory(null)} />}
              {pluginFilter.map((id) => (
                <FilterChip
                  key={id}
                  label={plugins[id].label}
                  onRemove={() => setPluginFilter(pluginFilter.filter((other) => other !== id))}
                />
              ))}
              {filtered && (
                <Button
                  variant="link"
                  size="sm"
                  className="h-auto px-1 text-[12.5px]"
                  onClick={() => {
                    setCategory(null);
                    setPluginFilter([]);
                  }}
                >
                  {__('Clear all', 'yay-automation')}
                </Button>
              )}
            </div>
            <Select value={sort} onValueChange={(value) => setSort(value as SortKey)}>
              <SelectTrigger size="sm" className="w-40 shrink-0" aria-label={__('Sort templates', 'yay-automation')}>
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
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {visible.map((template) => (
                <TemplateCard key={template.id} template={template} />
              ))}
            </div>
          )}
        </div>
      </div>
    </>
  );
}

/** Step tiles a card's strip shows before "+N". */
const STRIP_STEPS = 5;
/** Plugin icons a card shows before "+N". */
const STACK_PLUGINS = 3;

/** The template's steps as tiles joined by short lines, so its shape shows before it's opened. */
function StepStrip({ template }: { template: WorkflowTemplate }) {
  const { steps, linked } = cardData.get(template.id)!;
  const shown = steps.slice(0, STRIP_STEPS);
  const line = <span className="bg-muted-foreground/40 h-px w-3.5 shrink-0" />;
  const gap = <span className="w-2.5 shrink-0" />;
  return (
    <div className="bg-muted/40 flex items-center border-b bg-[radial-gradient(var(--border)_1px,transparent_1px)] bg-size-[12px_12px] px-4 py-3.5">
      {shown.map((node, i) => {
        const stepDef = stepOf(node.data);
        return (
          <span key={node.id} className="flex items-center" title={stepDef?.label}>
            {i > 0 && (linked[i] ? line : gap)}
            <StepTile kind={node.kind} icon={stepDef?.icon} empty={!stepDef} size="sm" />
          </span>
        );
      })}
      {steps.length > STRIP_STEPS && (
        <>
          {linked[STRIP_STEPS] ? line : gap}
          <span className="bg-muted text-muted-foreground rounded-md px-1.75 py-0.5 text-[11.5px] font-medium">
            +{steps.length - STRIP_STEPS}
          </span>
        </>
      )}
    </div>
  );
}

/** Overlapping plugin icons, up to 3 then "+N"; each name on hover. */
function PluginStack({ ids }: { ids: WorkflowPlugin[] }) {
  const rest = ids.slice(STACK_PLUGINS);
  return (
    <span className="flex items-center">
      {ids.slice(0, STACK_PLUGINS).map((id, i) => (
        <PluginIcon key={id} plugin={id} size="sm" className={cn('ring-card ring-2', i > 0 && '-ml-1.25')} />
      ))}
      {rest.length > 0 && (
        <span
          title={rest.map((id) => plugins[id].label).join(', ')}
          className="bg-muted text-muted-foreground-600 ring-card -ml-1.25 flex size-5.5 items-center justify-center rounded-md text-[11px] font-semibold ring-2"
        >
          +{rest.length}
        </span>
      )}
    </span>
  );
}

function TemplateCard({ template }: { template: WorkflowTemplate }) {
  const { plugins: used, comingSoon } = cardData.get(template.id)!;
  return (
    <Link
      to={`/workflows/templates/${template.id}`}
      state={{ fromLibrary: true } satisfies LibraryNavState}
      className="bg-card text-card-foreground hover:border-primary focus-visible:border-primary flex flex-col overflow-hidden rounded-xl border transition-[border-color,box-shadow] outline-none hover:shadow-[0_6px_18px_-10px_color-mix(in_srgb,var(--primary)_60%,transparent)]"
    >
      <StepStrip template={template} />
      <div className="flex flex-1 flex-col gap-1 px-4 pt-3.5 pb-4">
        <span className="text-muted-foreground text-[11px]/4 font-semibold tracking-wide uppercase">
          {categoryLabels[template.category]}
        </span>
        <span className="font-semibold">{template.name}</span>
        <p className="text-muted-foreground line-clamp-2 text-[13px]">{template.description}</p>
        <div className="mt-auto flex items-center justify-between gap-2 pt-3">
          <PluginStack ids={used} />
          {comingSoon && <ComingSoonChip />}
        </div>
      </div>
    </Link>
  );
}
