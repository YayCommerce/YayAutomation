import { useState, type ReactNode } from 'react';
import type { StepId, WorkflowPlugin } from '@yay-automation/types';
import { ArrowLeft, ChevronRight, Search } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { PluginIcon, plugins } from '../../shared/ui/PluginIcon';
import { steps, type Step } from '../../steps';
import { itemLabel } from '../../steps/core/data';
import { comingSoonLabel } from '../../steps/core/pending';

type PickableKind = 'trigger' | 'action';

const categoryLabels: Record<NonNullable<Step['category']>, string> = {
  email: __('Email', 'yay-automation'),
  order: __('Order', 'yay-automation'),
  coupon: __('Coupons', 'yay-automation'),
  flow: __('Flow', 'yay-automation'),
};

const searchLabels: Record<PickableKind, string> = {
  trigger: __('Search plugins and triggers', 'yay-automation'),
  action: __('Search plugins and actions', 'yay-automation'),
};

const matches = (text: string, query: string) => text.toLowerCase().includes(query.trim().toLowerCase());

/**
 * Picks a trigger or action step: active plugins first (icon, name, count), then that plugin's
 * steps. Searching from the plugin list searches every step. With `available` (actions), steps
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
    (stepDef) => stepDef.kind === kind && stepDef.plugin && plugins[stepDef.plugin].active,
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
            stepDef.comingSoon
              ? comingSoonLabel
              : missing &&
                /* translators: %s: data item, e.g. "Order" */
                sprintf(
                  __('Requires %s data. Choose a trigger or add a step above that provides it.', 'yay-automation'),
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
    // Actions come in sections (Email, Order, …); triggers are one list.
    const sections =
      kind === 'action' ? [...new Set(own.map((stepDef) => stepDef.category!))] : own.length ? [null] : [];
    list = sections.map((category) => (
      <section key={category ?? 'all'}>
        {category && (
          <h3 className="text-muted-foreground bg-muted/50 px-4 py-1.5 text-xs font-medium">
            {categoryLabels[category]}
          </h3>
        )}
        {stepRows(own.filter((stepDef) => !category || stepDef.category === category))}
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
        <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden />
      </PickerRow>
    ));
  }

  return (
    <div className="flex min-h-0 flex-col gap-3">
      {plugin && (
        <div className="bg-muted/50 flex items-center gap-3 rounded-lg border p-2">
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={__('Back to plugins', 'yay-automation')}
            onClick={() => open(null)}
          >
            <ArrowLeft />
          </Button>
          <PluginIcon plugin={plugin} />
          <span className="min-w-0">
            <span className="block text-sm font-medium">{plugins[plugin].label}</span>
            <span className="text-muted-foreground block text-xs">{plugins[plugin].description}</span>
          </span>
        </div>
      )}

      <div className="overflow-hidden rounded-lg border">
        <div className="border-b p-2">
          <InputGroup>
            <InputGroupAddon>
              <Search />
            </InputGroupAddon>
            <InputGroupInput
              // Typing is the reason the picker is open.
              autoFocus
              value={query}
              placeholder={
                plugin ? sprintf(__('Search %s', 'yay-automation'), plugins[plugin].label) : searchLabels[kind]
              }
              onChange={(event) => setQuery(event.target.value)}
            />
          </InputGroup>
        </div>
        <div className="divide-y">
          {list}
          {Array.isArray(list) && !list.length && (
            <p className="text-muted-foreground p-4 text-sm">{__('Nothing found.', 'yay-automation')}</p>
          )}
        </div>
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
        'flex w-full cursor-pointer items-center gap-3 px-4 py-3 text-left text-sm',
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
      {showPlugin && <PluginIcon plugin={stepDef.plugin!} />}
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-2 font-medium">
          {stepDef.label}
          {stepDef.comingSoon && <Badge variant="dimmed">{comingSoonLabel}</Badge>}
        </span>
        <span className="text-muted-foreground block text-xs">{stepDef.description}</span>
      </span>
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
