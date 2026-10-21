import type { MouseEventHandler } from 'react';
import type { WorkflowTemplate } from '@yaymatik/types';
import { ChevronLeft, Info } from 'lucide-react';
import { Link, useLocation, useNavigate, useParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Badge } from '@/components/ui/badge';
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button, LoadingButton } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { stepsInOrder } from '../../model/graph';
import { kindMeta } from '../../shared/canvas/NodeCardView';
import { ReadOnlyCanvas } from '../../shared/canvas/ReadOnlyCanvas';
import { EditorLayout, EditorPlaceholder } from '../../shared/layout/EditorLayout';
import { LIBRARY_PATH, readLibraryNavState } from '../../shared/nav/library.nav';
import { PluginIcon, plugins } from '../../shared/ui/PluginIcon';
import { stepOf, SummaryText } from '../../steps';
import { generateCouponStep, type GenerateCouponConfig } from '../../steps/actions/generate-coupon';
import { FreeShippingWarning, needsShippingSetup } from '../../steps/actions/generate-coupon/FreeShippingWarning';
import { comingSoonLabel } from '../../steps/core/pending';
import { StepTile } from '../../steps/core/StepTile';
import { categoryLabels, findTemplate, isComingSoon, pluginsOf, sendsCustomerEmail, versionsOf } from '../../templates';
import { installFirst, useCreateDraft } from './useCreateDraft';

/** Names of the plugins a template uses that aren't installed. */
const missingPlugins = (template: WorkflowTemplate) =>
  pluginsOf(template.graph)
    .filter((id) => !plugins[id].active)
    .map((id) => plugins[id].label);

export default function TemplatePreviewPage() {
  const { templateId = '' } = useParams();
  const template = findTemplate(templateId);
  const navigate = useNavigate();
  const location = useLocation();
  const { createDraft, isCreating } = useCreateDraft();

  // The breadcrumb returns to the open library. Opened from a card → pop that entry;
  // otherwise (bookmark, reload) → link. × leaves the flow for the workflow list (EditorLayout default).
  const toLibrary: MouseEventHandler<HTMLAnchorElement> = (event) => {
    if (readLibraryNavState(location.state).fromLibrary) {
      event.preventDefault();
      navigate(-1);
    }
  };

  if (!template) {
    return <EditorPlaceholder error={new Error(__('Template not found.', 'yaymatik'))} />;
  }

  const useLabel = __('Use template', 'yaymatik');

  return (
    <EditorLayout
      title={
        <Breadcrumb className="min-w-0">
          <BreadcrumbList className="flex-nowrap gap-1.5 text-sm sm:gap-1.5">
            <BreadcrumbItem className="shrink-0">
              <BreadcrumbLink asChild>
                <Link to={LIBRARY_PATH} onClick={toLibrary} className="inline-flex items-center gap-1">
                  <ChevronLeft className="size-4" aria-hidden />
                  {__('Template Library', 'yaymatik')}
                </Link>
              </BreadcrumbLink>
            </BreadcrumbItem>
            <BreadcrumbSeparator />
            <BreadcrumbItem className="min-w-0">
              <BreadcrumbPage className="truncate font-medium">{template.name}</BreadcrumbPage>
            </BreadcrumbItem>
          </BreadcrumbList>
        </Breadcrumb>
      }
      actions={
        isComingSoon(template.graph) ? (
          <Tooltip>
            <TooltipTrigger asChild>
              {/* Disabled buttons swallow pointer events, so a wrapper carries the tooltip. */}
              <span tabIndex={0}>
                <Button size="sm" disabled>
                  {useLabel}
                </Button>
              </span>
            </TooltipTrigger>
            <TooltipContent>{comingSoonLabel}</TooltipContent>
          </Tooltip>
        ) : (
          <LoadingButton
            size="sm"
            loading={isCreating}
            disabled={isCreating}
            onClick={() => createDraft(template.name, template.graph, missingPlugins(template))}
          >
            {useLabel}
          </LoadingButton>
        )
      }
    >
      {/* Narrow screens: the panel goes under the canvas. */}
      <div className="flex size-full max-md:flex-col">
        <div className="relative min-h-0 min-w-0 flex-1">
          <ReadOnlyCanvas key={template.id} graph={template.graph} maxZoom={2} />
        </div>
        {/* Keyed: another version opens with the panel scrolled to the top. */}
        <TemplateSidePanel key={template.id} template={template} navState={location.state} />
      </div>
    </EditorLayout>
  );
}

const groupHeading = 'text-muted-foreground mb-2 text-[11px] font-semibold tracking-wider uppercase';

/** Coupon steps whose free shipping no shipping method takes as is. */
const shippingSetupCoupons = (template: WorkflowTemplate) =>
  template.graph.nodes
    .filter((node) => node.data.block === 'generate_coupon')
    .map((node) => generateCouponStep.normalize(node.data.config) as GenerateCouponConfig)
    .filter(needsShippingSetup);

/**
 * Docked beside the canvas: what the template is, what it does step by step, its plugins, its other
 * versions, what to set up first, what's next.
 */
function TemplateSidePanel({ template, navState }: { template: WorkflowTemplate; navState: unknown }) {
  const incoming = new Map(template.graph.edges.map((edge) => [edge.target, edge.sourceHandle]));
  const versions = versionsOf(template);
  const setupCoupon = shippingSetupCoupons(template)[0];

  return (
    <aside className="bg-background flex w-75 shrink-0 flex-col gap-5 overflow-y-auto border-l p-4.5 text-[13.5px] max-md:max-h-[45%] max-md:w-full max-md:border-t max-md:border-l-0">
      <div>
        <span className="text-muted-foreground text-[11px]/4 font-semibold tracking-wide uppercase">
          {categoryLabels[template.category]}
        </span>
        <h2 className="text-[17px]/snug font-semibold">{template.name}</h2>
        <p className="text-muted-foreground mt-1.5 text-[13px]">{template.description}</p>
      </div>

      <section>
        <h3 className={groupHeading}>{__('What it does', 'yaymatik')}</h3>
        {/* Gap, not `space-y-*`: WP admin's `li { margin-bottom }` beats its zero-specificity margins. */}
        <ol className="flex flex-col gap-2.5">
          {stepsInOrder(template.graph).map((node) => {
            const stepDef = stepOf(node.data);
            const branch = incoming.get(node.id);
            return (
              <li key={node.id} className="flex items-start gap-2.5">
                <StepTile kind={node.kind} icon={stepDef?.icon} empty={!stepDef} size="sm" />
                {/* Same text as the step's card: its name, then its summary. */}
                <span className="min-w-0 flex-1">
                  <span className="block font-medium">
                    {branch && (
                      <span className="text-muted-foreground font-normal">
                        {branch === 'then' ? __('Yes:', 'yaymatik') : __('No:', 'yaymatik')}{' '}
                      </span>
                    )}
                    {stepDef?.label ?? kindMeta[node.kind].label}
                  </span>
                  {stepDef && (
                    <span className="text-muted-foreground-600 [&_strong]:text-card-foreground block text-[13px]">
                      <SummaryText text={stepDef.summary(node.data.config)} />
                    </span>
                  )}
                </span>
              </li>
            );
          })}
        </ol>
      </section>

      <section>
        <h3 className={groupHeading}>{__('Plugins used', 'yaymatik')}</h3>
        <ul className="flex flex-col gap-2">
          {pluginsOf(template.graph).map((plugin) => (
            <li key={plugin} className="flex items-center gap-2 rounded-lg border px-2.5 py-2">
              <PluginIcon plugin={plugin} />
              <span className="min-w-0 flex-1 truncate font-medium">{plugins[plugin].label}</span>
              {plugins[plugin].active ? (
                <Badge variant="success-soft">{__('Installed', 'yaymatik')}</Badge>
              ) : (
                <Badge variant="warning-soft">{__('Not installed', 'yaymatik')}</Badge>
              )}
            </li>
          ))}
        </ul>
        {/* Using it still works; activating needs the plugin. */}
        {installFirst(missingPlugins(template)) && (
          <p className="text-warning-foreground bg-warning/20 mt-2 rounded-md px-2.5 py-1.5 text-[13px]">
            {installFirst(missingPlugins(template))}
          </p>
        )}
      </section>

      {versions.length > 0 && (
        <section>
          <h3 className={groupHeading}>{__('Other versions', 'yaymatik')}</h3>
          <ul className="flex flex-col gap-1.5">
            {versions.map((version) => (
              <li key={version.id}>
                {/* Replace, keeping the state: the breadcrumb still goes back to the library. */}
                <Link
                  to={`/workflows/templates/${version.id}`}
                  replace
                  state={navState}
                  className="text-primary font-medium hover:underline"
                >
                  {version.name}
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}

      {/* Hidden when nothing is missing. */}
      {setupCoupon && (
        <section>
          <h3 className={groupHeading}>{__('Before you start', 'yaymatik')}</h3>
          <FreeShippingWarning config={setupCoupon} />
        </section>
      )}

      <p className="bg-primary/8 flex items-start gap-2 rounded-lg px-3 py-2.5 text-[13px]">
        <Info className="text-primary mt-0.5 size-4 shrink-0" aria-hidden />
        {sendsCustomerEmail(template.graph)
          ? __('Using it creates a draft. Review the emails, then activate it.', 'yaymatik')
          : __('Using it creates a draft. Review it, then activate it.', 'yaymatik')}
      </p>
    </aside>
  );
}
