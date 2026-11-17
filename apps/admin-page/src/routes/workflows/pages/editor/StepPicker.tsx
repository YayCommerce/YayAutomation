import { useState, type ReactNode } from 'react';
import { ProTeaser } from '@edition/teaser';
import type { StepId, WorkflowPlugin } from '@yaymatik/types';
import { ChevronLeft, ChevronRight, Search } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { PluginIcon, plugins } from '../../shared/ui/PluginIcon';
import { steps, type Step } from '../../steps';
import { itemLabel } from '../../steps/core/data';
import { StepTile } from '../../steps/core/StepTile';

type PickableKind = 'trigger' | 'action';

/** Labels by id from PHP, other plugins' sections included. */
const categoryLabels = window.yayMatikMeta.automation.categories;

const searchLabels: Record<PickableKind, string> = {
  trigger: __('Search plugins and triggers', 'yaymatik'),
  action: __('Search plugins and actions', 'yaymatik'),
};

const matches = (text: string, query: string) => text.toLowerCase().includes(query.trim().toLowerCase());

/**
 * Picks a trigger or action step: active plugins first (logo, name, count), then that plugin's
 * steps, each with the same tile as its card. "All plugins › WooCommerce" leads back. Searching from the plugin list searches every step. With `available` (actions), steps
 * needing data the step can't read are dimmed and can't be picked; so are steps still coming soon.
 */
export function StepPicker({
  kind,
  available,
  onPick,
}: {
  kind: PickableKind;
  available?: string[];
  onPick: (stepId: StepId) => void;
}) {
  const [plugin, setPlugin] = useState<WorkflowPlugin | null>(null);
  const [query, setQuery] = useState('');

  const choices = Object.values(steps).filter(
    (stepDef) => stepDef.kind === kind && !!stepDef.plugin && !!plugins[stepDef.plugin]?.active,
  );
  const pluginIds = [...new Set(choices.map((stepDef) => stepDef.plugin!))];
  const missingData = (stepDef: Step) => stepDef.requires.find((item) => available && !available.includes(item));

  const open = (id: WorkflowPlugin | null) => {
    setPlugin(id);
    setQuery('');
  };

  const stepRows = (list: Step[]) =>
    list.map((stepDef) => {
      const missing = missingData(stepDef);
      return (
        <StepRow
          key={stepDef.id}
          step={stepDef}
          showPlugin={!plugin}
          disabledReason={
            missing &&
            /* translators: %s: data item, e.g. "Order" */
            sprintf(
              __('Requires %s data. Choose a trigger or add a step above that provides it.', 'yaymatik'),
              itemLabel(missing),
            )
          }
          onPick={() => onPick(stepDef.id)}
        />
      );
    });

  let list: ReactNode;
  if (plugin) {
    const own = choices.filter((stepDef) => stepDef.plugin === plugin && matches(stepDef.label, query));
    // Another plugin's step may have no category: no heading for those.
    list = [...new Set(own.map((stepDef) => stepDef.category))].map((category) => (
      <section key={category ?? 'all'}>
        {category && (
          <h3 className="text-muted-foreground px-2 pt-2.5 pb-1 text-[11px] font-semibold tracking-wider uppercase">
            {categoryLabels[category] ?? category}
          </h3>
        )}
        {stepRows(own.filter((stepDef) => stepDef.category === category))}
      </section>
    ));
  } else if (query.trim()) {
    list = stepRows(choices.filter((stepDef) => matches(`${stepDef.label} ${plugins[stepDef.plugin!].label}`, query)));
  } else {
    list = pluginIds.map((id) => (
      <PickerRow key={id} onClick={() => open(id)}>
        <PluginIcon plugin={id} />
        <span className="min-w-0 flex-1">
          <span className="flex items-center gap-2 font-medium">
            {plugins[id].label}
            <Badge variant="dimmed">{choices.filter((stepDef) => stepDef.plugin === id).length}</Badge>
          </span>
          <span className="text-muted-foreground block text-xs">{plugins[id].description}</span>
        </span>
        <ChevronRight className="text-muted-foreground size-3.5 shrink-0" aria-hidden />
      </PickerRow>
    ));
  }

  return (
    <div className="flex min-h-0 flex-col gap-2.5">
      <InputGroup>
        <InputGroupAddon>
          <Search />
        </InputGroupAddon>
        <InputGroupInput
          // Typing is the reason the picker is open.
          autoFocus
          value={query}
          placeholder={
            plugin
              ? sprintf(
                  /* translators: %s: plugin name, e.g. "WooCommerce" */ __('Search %s', 'yaymatik'),
                  plugins[plugin].label,
                )
              : searchLabels[kind]
          }
          onChange={(event) => setQuery(event.target.value)}
        />
      </InputGroup>
      {plugin && (
        <Breadcrumb>
          <BreadcrumbList className="gap-1.5 text-[13px] sm:gap-1.5">
            <BreadcrumbItem>
              <BreadcrumbLink asChild>
                <button
                  type="button"
                  className="inline-flex cursor-pointer items-center gap-1"
                  onClick={() => open(null)}
                >
                  <ChevronLeft className="size-3.5" aria-hidden />
                  {__('All plugins', 'yaymatik')}
                </button>
              </BreadcrumbLink>
            </BreadcrumbItem>
            <BreadcrumbSeparator />
            <BreadcrumbItem>
              <BreadcrumbPage className="font-semibold">{plugins[plugin].label}</BreadcrumbPage>
            </BreadcrumbItem>
          </BreadcrumbList>
        </Breadcrumb>
      )}
      <div className="-mx-2 flex flex-col">
        {list}
        {Array.isArray(list) && !list.length && (
          <p className="text-muted-foreground p-2 text-sm">{__('Nothing found.', 'yaymatik')}</p>
        )}
        {ProTeaser && !plugin && !query.trim() && <ProTeaser place={kind} />}
      </div>
    </div>
  );
}

function PickerRow({ onClick, disabled, children }: { onClick: () => void; disabled?: boolean; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={cn(
        'flex w-full cursor-pointer items-center gap-3 rounded-lg p-2 text-left text-sm',
        disabled ? 'cursor-not-allowed opacity-50' : 'hover:bg-accent',
      )}
    >
      {children}
    </button>
  );
}

function StepRow({
  step: stepDef,
  showPlugin,
  disabledReason,
  onPick,
}: {
  step: Step;
  showPlugin: boolean;
  disabledReason?: string;
  onPick: () => void;
}) {
  const row = (
    <PickerRow onClick={onPick} disabled={!!disabledReason}>
      <StepTile kind={stepDef.kind} icon={stepDef.icon} size="md" />
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-2 font-medium">
          {stepDef.label}
          {/* Searching every plugin: say whose step it is. */}
          {showPlugin && <span className="text-muted-foreground font-normal">{plugins[stepDef.plugin!].label}</span>}
        </span>
        <span className="text-muted-foreground block truncate text-xs" title={stepDef.description}>
          {stepDef.description}
        </span>
      </span>
      {!disabledReason && <ChevronRight className="text-muted-foreground size-3.5 shrink-0" aria-hidden />}
    </PickerRow>
  );
  if (!disabledReason) return row;

  // Disabled buttons swallow pointer events, so a wrapper carries the tooltip.
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <div>{row}</div>
      </TooltipTrigger>
      <TooltipContent>{disabledReason}</TooltipContent>
    </Tooltip>
  );
}
