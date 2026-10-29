import { useStore } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

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
        <CardTitle className="text-base">{__('Cart tracking', 'yaymatik')}</CardTitle>
        <CardDescription>
          {__('Lets the abandoned cart and abandoned checkout workflows find carts and email shoppers.', 'yaymatik')}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <FieldGroup>
          <SwitchSetting
            form={form}
            name="cartTracking"
            label={__('Track carts', 'yaymatik')}
            description={
              tracking
                ? __(
                    'On. Carts of logged-in customers and guests are tracked. Emails guests type at checkout are saved before they place the order.',
                    'yaymatik',
                  )
                : __(
                    'Off by default. Workflows with a cart trigger don’t start while it’s off. When on, emails guests type at checkout are saved before they place the order.',
                    'yaymatik',
                  )
            }
            serverErrors={serverErrors}
          />
          {tracking && (
            <>
              <FieldSeparator />
              <NumberSetting
                form={form}
                name="abandonMinutes"
                label={__('Count a cart as abandoned after', 'yaymatik')}
                description={__(
                  'Minutes without activity, 15 to 1440. Reminder times in cart workflows count from then.',
                  'yaymatik',
                )}
                unit={__('minutes', 'yaymatik')}
                min={15}
                max={1440}
                serverErrors={serverErrors}
              />
              <FieldSeparator />
              <SwitchSetting
                form={form}
                name="captureGuestEmail"
                label={__('Capture guest emails at checkout', 'yaymatik')}
                description={__(
                  'Saves the email a guest types at checkout, on classic and block checkout, before the order is placed, so the cart can be recovered.',
                  'yaymatik',
                )}
                serverErrors={serverErrors}
              />
            </>
          )}
          <FieldSeparator />
          <NumberSetting
            form={form}
            name="cartRetentionDays"
            label={__('Delete old carts after', 'yaymatik')}
            description={__(
              'Carts with no activity for this long are deleted, with their email, 7 to 365 days. Applies even while tracking is off.',
              'yaymatik',
            )}
            unit={__('days', 'yaymatik')}
            min={7}
            max={365}
            serverErrors={serverErrors}
          />
        </FieldGroup>
      </CardContent>
    </Card>
  );
}
