import { useMemo, useState, type ReactNode } from 'react';
import type { TemplateCategory, WorkflowNode, WorkflowPlugin, WorkflowTemplate } from '@yaymatik/types';
import { SearchX } from 'lucide-react';
import { Link } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { stepsInOrder } from '../../../model/graph';
import type { LibraryNavState } from '../../../shared/nav/library.nav';
import { PluginIcon, plugins } from '../../../shared/ui/PluginIcon';
import { WarningBadge } from '../../../shared/ui/WarningBadge';
import { stepOf } from '../../../steps';
import { StepTile } from '../../../steps/core/StepTile';
import { categoryLabels, needsCartTracking, pluginsOf, templates, workflowRank } from '../../../templates';
import { usesAnyOf } from './filter';
import { countLabel, FilterChip, LibrarySidebar } from './LibraryFilters';
import { SearchInput } from './SearchInput';

type SortKey = 'popular' | 'newest' | 'name';

const sortOptions: { value: SortKey; label: string }[] = [
  { value: 'popular', label: __('Most popular', 'yaymatik') },
  { value: 'newest', label: __('Newest', 'yaymatik') },
  { value: 'name', label: __('Name (A–Z)', 'yaymatik') },
];

const compare: Record<SortKey, (a: WorkflowTemplate, b: WorkflowTemplate) => number> = {
  // Versions of one workflow keep their list order (a stable sort).
  popular: (a, b) => workflowRank[a.workflow] - workflowRank[b.workflow],
  newest: (a, b) => b.createdAt.localeCompare(a.createdAt),
  name: (a, b) => a.name.localeCompare(b.name),
};

export function TemplatesDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        // Nearly full screen, like the create dialog; a fixed height, so filtering doesn't resize it.
        className="flex h-[calc(100vh-2rem)] flex-col gap-0 p-0 sm:max-w-[calc(100vw-2rem)]"
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
    return [t.id, { steps, linked, plugins: pluginsOf(t.graph) }] as const;
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
  const clearFilters = () => {
    setCategory(null);
    setPluginFilter([]);
  };

  return (
    <>
      {/* Wide: title, search centered on the dialog, then the dialog's close button (absolute, in the
          empty third column). Narrower: the search gets its own full-width row. */}
      <div className="flex flex-col gap-3 border-b px-5 py-4 xl:grid xl:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] xl:items-center xl:gap-6">
        {/* pr-9 keeps the title clear of the close button. */}
        <div className="min-w-0 pr-9 xl:pr-0">
          <DialogTitle className="text-lg font-semibold">{__('Template Library', 'yaymatik')}</DialogTitle>
          <DialogDescription className="text-[13px]">
            {__('Ready-made workflows for WooCommerce. Pick one, review it, then activate.', 'yaymatik')}
          </DialogDescription>
        </div>
        <SearchInput
          value={search}
          onChange={setSearch}
          placeholder={__('Search templates', 'yaymatik')}
          className="w-full xl:w-120"
        />
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
                <Button variant="link" size="sm" className="h-auto px-1 text-[12.5px]" onClick={clearFilters}>
                  {__('Clear all', 'yaymatik')}
                </Button>
              )}
            </div>
            <Select value={sort} onValueChange={(value) => setSort(value as SortKey)}>
              <SelectTrigger size="sm" className="w-40 shrink-0" aria-label={__('Sort templates', 'yaymatik')}>
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
            <NoResults
              query={search.trim()}
              filtered={filtered}
              onClearSearch={() => setSearch('')}
              onClearFilters={clearFilters}
            />
          ) : (
            // As many 280px+ columns as fit, at most 4 (3 gaps of 1rem).
            <div className="grid grid-cols-[repeat(auto-fill,minmax(max(280px,calc((100%-3rem)/4)),1fr))] gap-4">
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

/**
 * Nothing matches: centred in the grid area, with a way out for each cause (the search, the
 * filters, or both).
 */
function NoResults({
  query,
  filtered,
  onClearSearch,
  onClearFilters,
}: {
  query: string;
  filtered: boolean;
  onClearSearch: () => void;
  onClearFilters: () => void;
}) {
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-2 px-4 py-7 text-center">
      <div className="bg-muted text-muted-foreground mb-1 flex h-13 w-18 items-center justify-center rounded-xl">
        <SearchX className="size-6 stroke-[1.6]" aria-hidden />
      </div>
      <p className="text-base font-semibold">{__('No templates found', 'yaymatik')}</p>
      <p className="text-muted-foreground max-w-[52ch] text-[13px]">
        {query
          ? sprintf(
              /* translators: %s: what was searched for */
              __('No templates match “%s”.', 'yaymatik'),
              query,
            )
          : __('Try a different filter.', 'yaymatik')}
      </p>
      {(query || filtered) && (
        <div className="mt-2 flex flex-wrap justify-center gap-2">
          {query && (
            <Button variant="outline" size="sm" onClick={onClearSearch}>
              {__('Clear search', 'yaymatik')}
            </Button>
          )}
          {filtered && (
            <Button variant="outline" size="sm" onClick={onClearFilters}>
              {__('Clear filters', 'yaymatik')}
            </Button>
          )}
        </div>
      )}
    </div>
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
          <span key={node.id} className="flex items-center">
            {i > 0 && (linked[i] ? line : gap)}
            <CardTip label={stepDef?.label}>
              <StepTile kind={node.kind} icon={stepDef?.icon} empty={!stepDef} size="sm" />
            </CardTip>
          </span>
        );
      })}
      {steps.length > STRIP_STEPS && (
        <>
          {linked[STRIP_STEPS] ? line : gap}
          <CardTip label={labelsOf(steps.slice(STRIP_STEPS))}>
            <span className="bg-muted text-muted-foreground rounded-md px-1.75 py-0.5 text-[11.5px] font-medium">
              +{steps.length - STRIP_STEPS}
            </span>
          </CardTip>
        </>
      )}
    </div>
  );
}

/** Overlapping plugin icons, up to 3 then "+N"; each name on hover ("+N": the rest). */
function PluginStack({ ids }: { ids: WorkflowPlugin[] }) {
  const rest = ids.slice(STACK_PLUGINS);
  return (
    <span className="flex items-center">
      {ids.slice(0, STACK_PLUGINS).map((id, i) => (
        <CardTip key={id} label={plugins[id].label} className={cn(i > 0 && '-ml-1.25')}>
          <PluginIcon plugin={id} size="sm" noTitle className="ring-card ring-2" />
        </CardTip>
      ))}
      {rest.length > 0 && (
        <CardTip label={rest.map((id) => plugins[id].label).join(', ')} className="-ml-1.25">
          <span className="bg-muted text-muted-foreground-600 ring-card flex size-5.5 items-center justify-center rounded-md text-[11px] font-semibold ring-2">
            +{rest.length}
          </span>
        </CardTip>
      )}
    </span>
  );
}

/** Steps' names, e.g. for a "+N" tooltip. */
const labelsOf = (nodes: WorkflowNode[]) =>
  nodes
    .map((node) => stepOf(node.data)?.label)
    .filter(Boolean)
    .join(', ');

/**
 * Kit tooltip over a card's icon. Lifted above the dialog (z-100002), like `HelpTip`. The trigger
 * is a span: the icons don't take a ref, and the card itself is the link.
 */
function CardTip({ label, className, children }: { label?: string; className?: string; children: ReactNode }) {
  if (!label) return children;
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className={cn('flex', className)}>{children}</span>
      </TooltipTrigger>
      <TooltipContent side="top" className="z-100005">
        {label}
      </TooltipContent>
    </Tooltip>
  );
}

function TemplateCard({ template }: { template: WorkflowTemplate }) {
  const { plugins: used } = cardData.get(template.id)!;
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
          {needsCartTracking(template.graph) && <WarningBadge>{__('Needs cart tracking', 'yaymatik')}</WarningBadge>}
        </div>
      </div>
    </Link>
  );
}
