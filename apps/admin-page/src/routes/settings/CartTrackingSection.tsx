import { settingRanges } from '@edition/settings';
import { useStore } from '@tanstack/react-form';
import { __, sprintf } from '@wordpress/i18n';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldGroup, FieldSeparator } from '@/components/ui/field';
import { NumberSetting, SwitchSetting, type ServerErrors } from './SettingFields';
import type { SettingsForm } from './settingsForm';

/** While tracking is off only its switch and "Delete old carts after" show: old carts are deleted either way. */
export function CartTrackingSection({ form, serverErrors }: { form: SettingsForm; serverErrors: ServerErrors }) {
  const tracking = useStore(form.store, (state) => state.values.cartTracking);

  return (
    <Card>
      <CardHeader className="border-b">
        <CardTitle className="text-base">{__('Cart tracking', 'yaymatik-automation-for-woocommerce')}</CardTitle>
        <CardDescription>
          {__(
            'Lets the abandoned cart and abandoned checkout workflows find carts and email shoppers.',
            'yaymatik-automation-for-woocommerce',
          )}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <FieldGroup>
          <SwitchSetting
            form={form}
            name="cartTracking"
            label={__('Track carts', 'yaymatik-automation-for-woocommerce')}
            description={
              tracking
                ? __(
                    'On. Carts of logged-in customers and guests are tracked. Emails guests type at checkout are saved before they place the order.',
                    'yaymatik-automation-for-woocommerce',
                  )
                : __(
                    'Off by default. Workflows with a cart trigger don’t start while it’s off. When on, emails guests type at checkout are saved before they place the order.',
                    'yaymatik-automation-for-woocommerce',
                  )
            }
            serverErrors={serverErrors}
          />
          {tracking && (
            <>
              {settingRanges.abandonMinutes && (
                <>
                  <FieldSeparator />
                  <NumberSetting
                    form={form}
                    name="abandonMinutes"
                    label={__('Count a cart as abandoned after', 'yaymatik-automation-for-woocommerce')}
                    description={sprintf(
                      /* translators: 1: smallest number, 2: largest number */
                      __(
                        'Minutes without activity, %1$d to %2$d. Reminder times in cart workflows count from then.',
                        'yaymatik-automation-for-woocommerce',
                      ),
                      ...settingRanges.abandonMinutes,
                    )}
                    unit={__('minutes', 'yaymatik-automation-for-woocommerce')}
                    min={settingRanges.abandonMinutes[0]}
                    max={settingRanges.abandonMinutes[1]}
                    serverErrors={serverErrors}
                  />
                </>
              )}
              <FieldSeparator />
              <SwitchSetting
                form={form}
                name="captureGuestEmail"
                label={__('Capture guest emails at checkout', 'yaymatik-automation-for-woocommerce')}
                description={__(
                  'Saves the email a guest types at checkout, on classic and block checkout, before the order is placed, so the cart can be recovered.',
                  'yaymatik-automation-for-woocommerce',
                )}
                serverErrors={serverErrors}
              />
            </>
          )}
          <FieldSeparator />
          <NumberSetting
            form={form}
            name="cartRetentionDays"
            label={__('Delete old carts after', 'yaymatik-automation-for-woocommerce')}
            description={sprintf(
              /* translators: 1: smallest number, 2: largest number */
              __(
                'Carts with no activity for this long are deleted, with their email, %1$d to %2$d days. Applies even while tracking is off.',
                'yaymatik-automation-for-woocommerce',
              ),
              ...settingRanges.cartRetentionDays,
            )}
            unit={__('days', 'yaymatik-automation-for-woocommerce')}
            min={settingRanges.cartRetentionDays[0]}
            max={settingRanges.cartRetentionDays[1]}
            serverErrors={serverErrors}
          />
        </FieldGroup>
      </CardContent>
    </Card>
  );
}
