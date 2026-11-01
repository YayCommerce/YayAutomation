import { __ } from '@wordpress/i18n';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldGroup } from '@/components/ui/field';
import { NumberSetting, type ServerErrors } from './SettingFields';
import type { SettingsForm } from './settingsForm';

export function RunHistorySection({ form, serverErrors }: { form: SettingsForm; serverErrors: ServerErrors }) {
  return (
    <Card>
      <CardHeader className="border-b">
        <CardTitle className="text-base">{__('Run history', 'yaymatik')}</CardTitle>
        <CardDescription>
          {__('Runs and every step’s result, as shown on each workflow’s page.', 'yaymatik')}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <FieldGroup>
          <NumberSetting
            form={form}
            name="runRetentionDays"
            label={__('Keep run history for', 'yaymatik')}
            description={__(
              'Finished runs older than this are deleted once a day, including runs of deleted workflows, 7 to 730 days. Queued, running and waiting runs are kept.',
              'yaymatik',
            )}
            unit={__('days', 'yaymatik')}
            min={7}
            max={730}
            serverErrors={serverErrors}
          />
        </FieldGroup>
      </CardContent>
    </Card>
  );
}
