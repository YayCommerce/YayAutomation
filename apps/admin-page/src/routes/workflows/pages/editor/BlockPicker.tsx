import { useState, type ReactNode } from 'react';
import type { BlockId, WorkflowPlugin } from '@yay-automation/types';
import { ArrowLeft, ChevronRight, Search } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { blocks, type Block } from '../../blocks';
import { itemLabel } from '../../blocks/core/data';
import { comingSoonLabel } from '../../blocks/core/pending';
import { PluginIcon, plugins } from '../../shared/ui/PluginIcon';

type PickableKind = 'trigger' | 'action';

const categoryLabels: Record<NonNullable<Block['category']>, string> = {
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
 * Picks a trigger or action block: active plugins first (icon, name, count), then that plugin's
 * blocks. Searching from the plugin list searches every block. With `available` (actions), blocks
 * needing data the step can't read are dimmed and can't be picked; so are blocks still coming soon.
 */
export function BlockPicker({
  kind,
  available,
  onPick,
}: {
  kind: PickableKind;
  available?: string[];
  onPick: (block: BlockId) => void;
}) {
  const [plugin, setPlugin] = useState<WorkflowPlugin | null>(null);
  const [query, setQuery] = useState('');

  const choices = Object.values(blocks).filter(
    (block) => block.kind === kind && block.plugin && plugins[block.plugin].active,
  );
  const pluginIds = [...new Set(choices.map((block) => block.plugin!))];
  const missingData = (block: Block) => block.requires.find((item) => available && !available.includes(item));

  const open = (id: WorkflowPlugin | null) => {
    setPlugin(id);
    setQuery('');
  };

  const blockRows = (list: Block[]) =>
    list.map((block) => {
      const missing = missingData(block);
      return (
        <BlockRow
          key={block.id}
          block={block}
          showPlugin={!plugin}
          disabledReason={
            block.comingSoon
              ? comingSoonLabel
              : missing &&
                /* translators: %s: data item, e.g. "Order" */
                sprintf(
                  __('Requires %s data. Choose a trigger or add a step above that provides it.', 'yay-automation'),
                  itemLabel(missing),
                )
          }
          onPick={() => onPick(block.id)}
        />
      );
    });

  let list: ReactNode;
  if (plugin) {
    const own = choices.filter((block) => block.plugin === plugin && matches(block.label, query));
    // Actions come in sections (Email, Order, …); triggers are one list.
    const sections = kind === 'action' ? [...new Set(own.map((block) => block.category!))] : own.length ? [null] : [];
    list = sections.map((category) => (
      <section key={category ?? 'all'}>
        {category && (
          <h3 className="text-muted-foreground bg-muted/50 px-4 py-1.5 text-xs font-medium">
            {categoryLabels[category]}
          </h3>
        )}
        {blockRows(own.filter((block) => !category || block.category === category))}
      </section>
    ));
  } else if (query.trim()) {
    list = blockRows(choices.filter((block) => matches(`${block.label} ${plugins[block.plugin!].label}`, query)));
  } else {
    list = pluginIds.map((id) => (
      <PickerRow key={id} onClick={() => open(id)}>
        <PluginIcon plugin={id} />
        <span className="min-w-0 flex-1">
          <span className="flex items-center gap-2 font-medium">
            {plugins[id].label}
            <Badge variant="dimmed">{choices.filter((block) => block.plugin === id).length}</Badge>
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

function BlockRow({
  block,
  showPlugin,
  disabledReason,
  onPick,
}: {
  block: Block;
  showPlugin: boolean;
  disabledReason?: string;
  onPick: () => void;
}) {
  const row = (
    <PickerRow onClick={onPick} disabled={!!disabledReason}>
      {showPlugin && <PluginIcon plugin={block.plugin!} />}
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-2 font-medium">
          {block.label}
          {block.comingSoon && <Badge variant="dimmed">{comingSoonLabel}</Badge>}
        </span>
        <span className="text-muted-foreground block text-xs">{block.description}</span>
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
