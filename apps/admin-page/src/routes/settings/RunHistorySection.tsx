import { settingRanges } from '@edition/settings';
import { __, sprintf } from '@wordpress/i18n';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldGroup } from '@/components/ui/field';
import { NumberSetting, type ServerErrors } from './SettingFields';
import type { SettingsForm } from './settingsForm';

export function RunHistorySection({ form, serverErrors }: { form: SettingsForm; serverErrors: ServerErrors }) {
  return (
    <Card>
      <CardHeader className="border-b">
        <CardTitle className="text-base">{__('Run history', 'yaymatik-automation-for-woocommerce')}</CardTitle>
        <CardDescription>
          {__('Runs and every step’s result, as shown on each workflow’s page.', 'yaymatik-automation-for-woocommerce')}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <FieldGroup>
          <NumberSetting
            form={form}
            name="runRetentionDays"
            label={__('Keep run history for', 'yaymatik-automation-for-woocommerce')}
            description={sprintf(
              /* translators: 1: smallest number, 2: largest number */
              __(
                'Finished runs older than this are deleted once a day, including runs of deleted workflows, %1$d to %2$d days. Queued, running and waiting runs are kept.',
                'yaymatik-automation-for-woocommerce',
              ),
              ...settingRanges.runRetentionDays,
            )}
            unit={__('days', 'yaymatik-automation-for-woocommerce')}
            min={settingRanges.runRetentionDays[0]}
            max={settingRanges.runRetentionDays[1]}
            serverErrors={serverErrors}
          />
        </FieldGroup>
      </CardContent>
    </Card>
  );
}
