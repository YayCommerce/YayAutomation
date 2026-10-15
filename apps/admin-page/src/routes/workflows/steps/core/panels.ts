import type { ComponentType } from 'react';

import type { Config, StepFormProps } from './types';

const panels = new Map<string, ComponentType<StepFormProps<Config>>>();

/**
 * Internal (public API comes later): a custom form for a step whose PHP fields can't be drawn by
 * `StepForm`, e.g. a Yay plugin's step. Checks still come from the step's fields. Read when the
 * panel opens, so it may be called after the registry is built.
 */
export function registerStepPanel(stepId: string, Panel: ComponentType<StepFormProps<Config>>) {
  panels.set(stepId, Panel);
}

export const stepPanel = (stepId: string) => panels.get(stepId);
