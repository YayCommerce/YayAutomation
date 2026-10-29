import { ExternalLink, Mail } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldGroup } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupTextarea } from '@/components/ui/input-group';
import { describedBy, SettingText, type ServerErrors } from './SettingFields';
import type { SettingsForm } from './settingsForm';

const TEXT_MAX = 1000;

const { wp } = window.yayMatikMeta;

export function EmailsSection({
  form,
  serverErrors,
  unsubscribeDefault,
}: {
  form: SettingsForm;
  serverErrors: ServerErrors;
  unsubscribeDefault: string;
}) {
  const id = 'yaymatik-setting-unsubscribeText';
  const error = serverErrors.unsubscribeText;

  return (
    <Card>
      <CardHeader className="border-b">
        <CardTitle className="text-base">{__('Emails', 'yaymatik')}</CardTitle>
        <CardDescription>{__('What shoppers see around workflow emails.', 'yaymatik')}</CardDescription>
      </CardHeader>
      <CardContent>
        <FieldGroup>
          <form.Field name="unsubscribeText">
            {(field) => (
              <Field data-invalid={!!error}>
                <SettingText
                  id={id}
                  label={__('Unsubscribe page text', 'yaymatik')}
                  description={__(
                    'Shown after the shopper unsubscribes. Leave blank for the default text.',
                    'yaymatik',
                  )}
                  error={error}
                />
                <InputGroup>
                  <InputGroupTextarea
                    id={id}
                    className="min-h-19"
                    maxLength={TEXT_MAX}
                    placeholder={unsubscribeDefault}
                    value={field.state.value}
                    onChange={(event) => field.handleChange(event.target.value)}
                    onBlur={field.handleBlur}
                    aria-describedby={describedBy(id, error)}
                    aria-invalid={!!error}
                  />
                  <InputGroupAddon align="block-end" className="justify-end text-xs tabular-nums">
                    {field.state.value.length} / {TEXT_MAX}
                  </InputGroupAddon>
                </InputGroup>
              </Field>
            )}
          </form.Field>
          <Alert variant="muted" role="note">
            <Mail aria-hidden />
            <AlertDescription className="text-foreground flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
              <span>
                {__('Sender name, address and colors come from your WooCommerce email settings.', 'yaymatik')}
              </span>
              <a
                href={`${wp.adminUrl}admin.php?page=wc-settings&tab=email`}
                target="_blank"
                rel="noopener"
                className="text-link inline-flex items-center gap-1 font-medium"
              >
                {__('WooCommerce email settings', 'yaymatik')}
                <ExternalLink className="size-3.5" aria-hidden />
              </a>
            </AlertDescription>
          </Alert>
        </FieldGroup>
      </CardContent>
    </Card>
  );
}
