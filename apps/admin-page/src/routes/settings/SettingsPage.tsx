import { useRef, useState } from 'react';
import { useStore } from '@tanstack/react-form';
import type { Settings, SettingsResponse } from '@yaymatik/types';
import { X } from 'lucide-react';
import { createPortal } from 'react-dom';
import { __, sprintf } from '@wordpress/i18n';

import { useBeforeUnloadWarning } from '@/hooks/useBeforeUnloadWarning';
import { useRouteLeaveGuard } from '@/hooks/useRouteLeaveGuard';
import {
  AlertDialog,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button, LoadingButton } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { FieldGroup, FieldSeparator } from '@/components/ui/field';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { PageCard } from '@/components/widget/PageWidget';
import { useHeaderSlot } from '@/routes/layout/useHeaderSlot';
import { errorMessage } from '@/routes/workflows/api/workflow.api';
import { SettingsError } from './api/settings.api';
import { useSaveSettings, useSettingsQuery } from './api/settings.query';
import { CartTrackingSection } from './CartTrackingSection';
import { EmailsSection } from './EmailsSection';
import { HealthPanel } from './HealthPanel';
import { RunHistorySection } from './RunHistorySection';
import type { ServerErrors } from './SettingFields';
import { SettingsErrorAlert } from './SettingsErrorAlert';
import { changedSettings, toValues, useSettingsForm, type SettingsValues } from './settingsForm';

export default function SettingsPage() {
  const { data, error, isFetching, refetch } = useSettingsQuery();

  if (data) return <SettingsEditor settings={data} />;

  return (
    <PageCard>
      <div className="flex flex-col gap-4">
        <h1 className="sr-only">{__('Settings', 'yaymatik')}</h1>
        {error && !isFetching ? (
          <SettingsErrorAlert
            message={sprintf(
              /* translators: %s: why it failed */ __('Couldn’t load your settings. %s', 'yaymatik'),
              error.message,
            )}
            onRetry={() => refetch()}
          />
        ) : (
          [4, 2, 1].map((rows, index) => <CardSkeleton key={index} rows={rows} />)
        )}
      </div>
    </PageCard>
  );
}

function SettingsEditor({ settings }: { settings: SettingsResponse }) {
  const save = useSaveSettings();
  const [serverErrors, setServerErrors] = useState<ServerErrors>({});
  // A failed request; a 400's bar shows while any field it refused is still unedited.
  const [failure, setFailure] = useState<string | null>(null);
  const saved = toValues(settings);
  // The query only changes through this page's saves, which set this first.
  const savedRef = useRef(saved);
  // Whether the last submit saved: `handleSubmit` resolves the same way when validation stops it.
  const lastSaveOk = useRef(false);

  const clearServerError = (name: keyof Settings) =>
    setServerErrors((errors) => {
      if (!(name in errors)) return errors;
      const rest = { ...errors };
      delete rest[name];
      return rest;
    });

  // `saved` follows the query. After a save the query and the reset hold the same values, so
  // useForm's per-render option update finds nothing new and doesn't reset the form again.
  const form = useSettingsForm(
    saved,
    async (values) => {
      setFailure(null);
      setServerErrors({});
      try {
        const response = await save.mutateAsync(changedSettings(values, saved));
        lastSaveOk.current = true;
        savedRef.current = toValues(response);
        toast.success(__('Settings saved', 'yaymatik'));
        return savedRef.current;
      } catch (error) {
        const fieldErrors = error instanceof SettingsError ? error.fieldErrors : [];
        if (fieldErrors.length) {
          setServerErrors(Object.fromEntries(fieldErrors.map(({ field, message }) => [field, message])));
        } else {
          setFailure(errorMessage(error));
        }
        return null;
      }
    },
    clearServerError,
  );

  const submitting = useStore(form.store, (state) => state.isSubmitting);
  const dirty = useStore(form.store, (state) => isChanged(state.values, saved));
  useBeforeUnloadWarning(dirty);

  const running = useRef<Promise<boolean> | null>(null);
  /** Saves even with nothing changed; while a save runs, returns that save instead. True when it saved. */
  const submit = () => {
    running.current ??= (async () => {
      lastSaveOk.current = false;
      try {
        await form.handleSubmit();
        return lastSaveOk.current;
      } finally {
        running.current = null;
      }
    })();
    return running.current;
  };

  // Read at navigation time, so a save that just finished counts before the page re-renders.
  const leaveGuard = useRouteLeaveGuard(() => isChanged(form.state.values, savedRef.current));
  const [leaving, setLeaving] = useState(false);
  const saveAndLeave = async () => {
    setLeaving(true);
    const ok = await submit();
    setLeaving(false);
    if (ok) leaveGuard.confirmLeave();
    else leaveGuard.cancelLeave();
  };

  const headerSlot = useHeaderSlot();

  return (
    <PageCard>
      <div className="flex flex-col gap-4">
        <h1 className="sr-only">{__('Settings', 'yaymatik')}</h1>
        {failure && (
          <SettingsErrorAlert
            /* translators: %s: why it failed */
            message={sprintf(__('Couldn’t save your settings. %s', 'yaymatik'), failure)}
            onRetry={submit}
          />
        )}
        {Object.keys(serverErrors).length > 0 && (
          <SettingsErrorAlert
            message={sprintf(
              /* translators: %s: why it failed */
              __('Couldn’t save your settings. %s', 'yaymatik'),
              __('Fix the highlighted settings.', 'yaymatik'),
            )}
          />
        )}
        <CartTrackingSection form={form} serverErrors={serverErrors} />
        <EmailsSection form={form} serverErrors={serverErrors} unsubscribeDefault={settings.unsubscribeDefault} />
        <RunHistorySection form={form} serverErrors={serverErrors} />
        <HealthPanel />
      </div>

      {headerSlot &&
        createPortal(
          <LoadingButton loading={submitting} onClick={submit}>
            <span className="hidden sm:inline">{__('Save changes', 'yaymatik')}</span>
            <span className="sm:hidden">{__('Save', 'yaymatik')}</span>
          </LoadingButton>,
          headerSlot,
        )}

      <AlertDialog open={leaveGuard.showDialog} onOpenChange={(open) => !open && !leaving && leaveGuard.cancelLeave()}>
        <AlertDialogContent>
          <Button
            variant="ghost"
            size="icon-sm"
            className="absolute top-3 right-3"
            aria-label={__('Close', 'yaymatik')}
            disabled={leaving}
            onClick={leaveGuard.cancelLeave}
          >
            <X />
          </Button>
          <AlertDialogHeader>
            <AlertDialogTitle>{__('Unsaved changes', 'yaymatik')}</AlertDialogTitle>
            <AlertDialogDescription>
              {__('You have unsaved changes. Do you want to save them before leaving?', 'yaymatik')}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <Button variant="outline" disabled={leaving} onClick={leaveGuard.confirmLeave}>
              {__('Discard changes', 'yaymatik')}
            </Button>
            <LoadingButton loading={leaving} onClick={() => !leaving && saveAndLeave()}>
              {__('Save changes', 'yaymatik')}
            </LoadingButton>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </PageCard>
  );
}

const isChanged = (values: SettingsValues, saved: SettingsValues) =>
  Object.keys(changedSettings(values, saved)).length > 0;

function CardSkeleton({ rows }: { rows: number }) {
  return (
    <Card aria-hidden>
      <CardHeader className="border-b">
        <Skeleton className="h-4 w-35" />
        <Skeleton className="h-3 w-60 max-w-full" />
      </CardHeader>
      <CardContent>
        <FieldGroup>
          {Array.from({ length: rows }, (_, index) => (
            <div key={index} className="contents">
              {index > 0 && <FieldSeparator />}
              <div className="flex flex-col gap-2">
                <Skeleton className="h-3 w-full" />
                <Skeleton className="h-3 w-[70%]" />
              </div>
            </div>
          ))}
        </FieldGroup>
      </CardContent>
    </Card>
  );
}
