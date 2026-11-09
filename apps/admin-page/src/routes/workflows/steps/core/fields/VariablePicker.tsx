import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { Slot } from '@radix-ui/react-slot';
import { Blocks, ChevronLeft, ChevronRight, Search } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { alwaysAvailable, dataItems, fieldByKey, itemLabel, type DataField } from '../data';
import { stepIcons } from '../icons';
import { StepTile } from '../StepTile';
import { groupVariables, ruleOnlyVariables, type Variable, type VariableGroup } from '../variables';
import { useVariableScope, type VariableScope } from './variableScope';

const MODAL_WIDTH = 440;
const MODAL_HEIGHT = 480;

/**
 * Put on the element a picker edits (a criteria box, a value input, a form field) along with
 * `data-spotlight`: while the picker is open it stays bright above the dimmed step panel.
 */
export const spotlightTarget =
  'data-[spotlit=on]:bg-card data-[spotlit=on]:ring-card data-[spotlit=on]:relative data-[spotlit=on]:z-20 data-[spotlit=on]:rounded-lg data-[spotlit=on]:ring-8';

const matches = (variable: Variable, query: string) =>
  [variable.label, variable.key].some((text) => text.toLowerCase().includes(query.trim().toLowerCase()));

const groupKey = (group: VariableGroup) => `${group.source}:${group.id}`;

const countOf = (group: VariableGroup) => group.sections.reduce((sum, section) => sum + section.variables.length, 0);

/** Name, line and tile of a group: a data item tinted by the step that supplies it, or a step above. */
function describe(group: VariableGroup, { trigger, steps }: VariableScope) {
  if (group.source === 'blocks') {
    return {
      label: __('Blocks', 'yaymatik'),
      description: __('Ready-made parts of the email, e.g. a product grid or a coupon box', 'yaymatik'),
      // Neutral: blocks come from the trigger's data, the site and steps above.
      tile: <StepTile kind="neutral" icon={Blocks} />,
    };
  }
  if (group.source === 'step') {
    const step = steps[group.id];
    return {
      label: step?.label ?? group.id,
      description: step?.summary ?? '',
      tile: <StepTile kind={step?.kind ?? 'action'} icon={step?.icon} />,
    };
  }
  const item = dataItems.find((i) => i.id === group.id);
  const kind = trigger?.provides.includes(group.id)
    ? 'trigger'
    : alwaysAvailable.includes(group.id)
      ? 'neutral'
      : 'action';
  return {
    label: item?.label ?? group.id,
    description: item?.description ?? '',
    tile: <StepTile kind={kind} icon={item?.icon ? stepIcons[item.icon] : undefined} />,
  };
}

/**
 * "Insert variable": a modal beside the step panel, level with the element being edited (the
 * nearest `data-spotlight` around `children`). `conditionalOnly` / `filter` are for condition rules,
 * which compare data fields only (no step outputs, no related items' fields).
 */
export function VariablePicker({
  conditionalOnly = false,
  filter,
  types,
  autoOpen = false,
  blocks = false,
  onSelect,
  onDismiss,
  focusAfterPick,
  children,
}: {
  /** Data fields usable in conditions only. */
  conditionalOnly?: boolean;
  /** Data fields it accepts (e.g. the same type as the other side of a comparison). */
  filter?: (field: DataField) => boolean;
  /** "Types: …" line under the search, when `filter` narrows by type. */
  types?: string;
  /** Open as soon as it mounts (a criteria just added). */
  autoOpen?: boolean;
  /** Offer blocks: block data fields and block outputs (rich text fields). */
  blocks?: boolean;
  /** `block`: a block, to go on its own line. */
  onSelect: (key: string, block: boolean) => void;
  /** Closed without a pick. */
  onDismiss?: () => void;
  /**
   * Moves focus once it has closed after a pick (e.g. to the edited input); false when it couldn't,
   * and focus goes back to the opener as after Esc.
   */
  focusAfterPick?: () => boolean;
  /** The element that opens it. */
  children: ReactNode;
}) {
  const scope = useVariableScope();
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState<CSSProperties>();
  const [openKey, setOpenKey] = useState<string | null>(null);
  const [query, setQuery] = useState('');

  const forConditions = conditionalOnly || !!filter;
  const accepts = (variable: Variable) => {
    if (variable.block) return blocks && !forConditions;
    if (!forConditions) return true;
    // An item's own field only: `order.total`, not `order.customer.email` or `steps.<id>.<key>`.
    const field = variable.key.split('.').length === 2 ? fieldByKey(variable.key) : undefined;
    return !!field && (!conditionalOnly || field.conditional) && (!filter || filter(field));
  };
  // Built only while open: a criteria box holds several closed pickers.
  const offered = open
    ? [...scope.variables, ...(conditionalOnly ? ruleOnlyVariables(scope.variables) : [])].filter(accepts)
    : [];
  // One section per data item (its label) and per step (its card summary: two coupons tell apart).
  const blockSections = groupVariables(offered.filter((variable) => variable.block)).map((g) => ({
    path: g.id,
    label: g.source === 'data' ? itemLabel(g.id) : scope.steps[g.id]?.summary || scope.steps[g.id]?.label || g.id,
    variables: g.sections.flatMap((section) => section.variables),
  }));
  const blockGroup: VariableGroup | null = blockSections.length
    ? { id: 'blocks', source: 'blocks', sections: blockSections }
    : null;
  const groups = [
    ...groupVariables(offered.filter((variable) => !variable.block)),
    ...(blockGroup ? [blockGroup] : []),
  ];
  const group = groups.find((g) => groupKey(g) === openKey);

  // Focus goes back to the opener on close: the dialog has no DialogTrigger to return it to.
  const opener = useRef<HTMLElement | null>(null);
  const picked = useRef(false);
  // Marked while open: `data-dimmed` on the panel (it lifts and dims) and `data-spotlit` on the edited element.
  const marked = useRef<HTMLElement[]>([]);

  // Beside the step panel, level with the edited element, kept on screen; centered without a panel.
  const openFrom = (element: HTMLElement) => {
    opener.current = element;
    const panel = element.closest<HTMLElement>('[data-step-panel]');
    const target = element.closest<HTMLElement>('[data-spotlight]') ?? element;
    if (panel) {
      const { right } = panel.getBoundingClientRect();
      const { top } = target.getBoundingClientRect();
      setPosition({
        left: Math.max(16, Math.min(right + 12, window.innerWidth - MODAL_WIDTH - 16)),
        top: Math.max(16, Math.min(top, window.innerHeight - MODAL_HEIGHT - 16)),
        translate: 'none',
      });
      panel.dataset.dimmed = 'on';
      target.dataset.spotlit = 'on';
      marked.current = [panel, target];
    } else {
      setPosition(undefined);
    }
    setOpenKey(null);
    setQuery('');
    setOpen(true);
  };
  const close = (withPick: boolean) => {
    picked.current = withPick;
    setOpen(false);
    for (const element of marked.current) {
      delete element.dataset.dimmed;
      delete element.dataset.spotlit;
    }
    marked.current = [];
    if (!withPick) onDismiss?.();
  };
  const pick = (variable: Variable) => {
    onSelect(variable.key, variable.block);
    close(true);
  };
  const openGroup = (key: string | null) => {
    setOpenKey(key);
    setQuery('');
  };

  const slot = useRef<HTMLElement>(null);
  useEffect(() => {
    if (autoOpen && slot.current) openFrom(slot.current);
    // Only on mount: `autoOpen` means "opened by the action that created this".
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const opened = group && describe(group, scope);
  const dataGroups = groups.filter((g) => g.source === 'data');
  const stepGroups = groups.filter((g) => g.source === 'step');

  let context: ReactNode = null;
  if (opened) {
    context = (
      <span className="flex items-center gap-1.5">
        <button
          type="button"
          onClick={() => openGroup(null)}
          className="hover:text-foreground flex cursor-pointer items-center gap-1.5"
        >
          <ChevronLeft className="size-3.5" aria-hidden />
          {__('All data', 'yaymatik')}
        </button>
        <ChevronRight className="size-3.5" aria-hidden />
        <b className="text-foreground font-semibold">{opened.label}</b>
      </span>
    );
  } else if (scope.trigger && dataGroups.length && query.trim() === '') {
    context = (
      <>
        {__('Data from the trigger', 'yaymatik')}
        <StepTile kind="trigger" icon={scope.trigger.icon} size="sm" />
        <b className="text-foreground font-medium">{scope.trigger.label}</b>
      </>
    );
  }

  let list: ReactNode;
  const searching = query.trim() !== '';
  if (searching) {
    const found = (group ? [group] : groups).flatMap((g) =>
      g.sections.flatMap((s) => s.variables.filter((v) => matches(v, query))),
    );
    list = found.length ? (
      found.map((variable) => <VariableRow key={variable.key} variable={variable} onPick={pick} />)
    ) : (
      <Empty>{__('No variable found.', 'yaymatik')}</Empty>
    );
  } else if (group) {
    list = group.sections.map((section) => (
      <div key={section.path} className="flex flex-col">
        {section.label && <SectionHeading>{section.label}</SectionHeading>}
        {section.variables.map((variable) => (
          <VariableRow key={variable.key} variable={variable} onPick={pick} />
        ))}
      </div>
    ));
  } else if (groups.length) {
    const row = (g: VariableGroup) => (
      <GroupRow key={groupKey(g)} {...describe(g, scope)} count={countOf(g)} onOpen={() => openGroup(groupKey(g))} />
    );
    list = (
      <>
        {dataGroups.map(row)}
        {blockGroup && row(blockGroup)}
        {stepGroups.length > 0 && (
          <>
            <SectionHeading>{__('From earlier steps', 'yaymatik')}</SectionHeading>
            {stepGroups.map(row)}
          </>
        )}
      </>
    );
  } else {
    list = <Empty>{__('No data to insert here yet.', 'yaymatik')}</Empty>;
  }

  return (
    <>
      <Slot ref={slot} onClick={(event) => openFrom(event.currentTarget as HTMLElement)}>
        {children}
      </Slot>
      <Dialog open={open} onOpenChange={(next) => !next && close(false)}>
        {/* `nokey`: Backspace in the search mustn't reach ReactFlow's delete-selected-step shortcut. */}
        <DialogContent
          style={{ ...position, width: MODAL_WIDTH, height: MODAL_HEIGHT }}
          // Above the lifted step panel (z-100002), which sits above the overlay (z-100001).
          className="nokey z-100003 flex max-h-[calc(100vh-2rem)] max-w-[calc(100vw-2rem)] flex-col gap-0 overflow-hidden rounded-xl p-0 sm:max-w-[calc(100vw-2rem)]"
          onCloseAutoFocus={(event) => {
            event.preventDefault();
            if (!(picked.current && focusAfterPick?.())) opener.current?.focus();
          }}
        >
          <div className="py-3 pr-12 pl-4">
            <DialogTitle className="text-[15px] font-semibold">{__('Insert variable', 'yaymatik')}</DialogTitle>
            <DialogDescription className="sr-only">
              {__('Pick data from the workflow to use here.', 'yaymatik')}
            </DialogDescription>
          </div>
          <div className="px-3 pb-2.5">
            <InputGroup>
              <InputGroupAddon>
                <Search />
              </InputGroupAddon>
              <InputGroupInput
                autoFocus
                value={query}
                placeholder={
                  opened
                    ? /* translators: %s: a data item or step, e.g. "Order" */
                      sprintf(__('Search in %s', 'yaymatik'), opened.label)
                    : __('Search all data', 'yaymatik')
                }
                onChange={(event) => setQuery(event.target.value)}
              />
            </InputGroup>
            {types && (
              <p className="bg-muted text-muted-foreground mt-2 w-fit rounded-md px-2 py-0.5 text-xs">
                {
                  /* translators: %s: variable types, e.g. "Number" */
                  sprintf(__('Types: %s', 'yaymatik'), types)
                }
              </p>
            )}
          </div>
          {context && (
            <div className="text-muted-foreground flex items-center gap-1.5 px-4 pb-2.5 text-[12.5px]">{context}</div>
          )}
          <div className="flex min-h-0 flex-1 flex-col overflow-y-auto border-t p-2 pt-1.5">{list}</div>
        </DialogContent>
      </Dialog>
    </>
  );
}

function SectionHeading({ children }: { children: ReactNode }) {
  return (
    <p className="text-muted-foreground px-2 pt-2.5 pb-1 text-[11px] font-semibold tracking-wider uppercase">
      {children}
    </p>
  );
}

function Empty({ children }: { children: ReactNode }) {
  return <p className="text-muted-foreground p-2 text-sm">{children}</p>;
}

const rowClass = 'flex w-full items-center gap-3 rounded-lg p-2 text-left';

/** A data item or step: tile, name, line, how many variables, then `>`. */
function GroupRow({
  label,
  description,
  tile,
  count,
  onOpen,
}: {
  label: string;
  description: string;
  tile: ReactNode;
  count: number;
  onOpen: () => void;
}) {
  return (
    <button type="button" onClick={onOpen} className={cn(rowClass, 'hover:bg-accent cursor-pointer')}>
      {tile}
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-medium">{label}</span>
        <span className="text-muted-foreground block truncate text-[12.5px]">{description}</span>
      </span>
      <span className="bg-muted text-muted-foreground rounded-md px-1.75 text-[11.5px]/5 font-medium tabular-nums">
        {count}
      </span>
      <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden />
    </button>
  );
}

/** A variable: its name, then its merge-tag key. */
function VariableRow({ variable, onPick }: { variable: Variable; onPick: (variable: Variable) => void }) {
  return (
    <button type="button" onClick={() => onPick(variable)} className={cn(rowClass, 'hover:bg-accent cursor-pointer')}>
      <span className="flex min-w-0 flex-1 items-center gap-2 text-sm font-medium">
        <span className="truncate">{variable.label}</span>
      </span>
      <code className="bg-muted text-muted-foreground max-w-[55%] truncate rounded-[5px] px-1.5 py-px font-mono text-[11.5px]">
        {variable.key}
      </code>
    </button>
  );
}
