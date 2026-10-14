import { useForm, useStore } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

import { FieldGroup } from '@/components/ui/field';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { ConfigField } from '../../core/fields/ConfigField';
import { useMarkInvalid } from '../../core/fields/feedback';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import { TextField, ToggleField } from '../../core/fields/widgets';
import type { StepFormProps } from '../../core/types';
import { schema, type DiscountType, type GenerateCouponConfig } from './schema';

const { currencySymbol } = window.yayAutomationMeta.wc;

export const discountTypeLabels: Record<DiscountType, string> = {
  percent: __('Percentage', 'yay-automation'),
  fixed_cart: __('Fixed amount', 'yay-automation'),
  free_shipping: __('Free shipping', 'yay-automation'),
};

type TextKey = 'amount' | 'expiry_days' | 'minimum_spend' | 'code_prefix';
type ToggleKey = 'individual_use' | 'limit_to_customer';

export function GenerateCouponForm({ config, onChange }: StepFormProps<GenerateCouponConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();
  const discountType = useStore(form.store, (state) => state.values.discount_type);

  const textField = (name: TextKey, label: string, help?: string, placeholder?: string) => (
    <form.Field name={name}>
      {(field) => (
        <TextField
          id={`step-coupon-${name}`}
          label={label}
          help={help}
          placeholder={placeholder}
          inputMode={name === 'code_prefix' ? undefined : 'decimal'}
          value={field.state.value}
          onChange={field.handleChange}
          onBlur={field.handleBlur}
          invalid={markInvalid(field.state.meta)}
          errors={field.state.meta.errors}
        />
      )}
    </form.Field>
  );

  const toggleField = (name: ToggleKey, label: string, help: string) => (
    <form.Field name={name}>
      {(field) => (
        <ToggleField
          id={`step-coupon-${name}`}
          label={label}
          help={help}
          checked={field.state.value}
          onChange={field.handleChange}
        />
      )}
    </form.Field>
  );

  return (
    <FieldGroup>
      <form.Field name="discount_type">
        {(field) => (
          <ConfigField label={__('Discount type', 'yay-automation')} htmlFor="step-coupon-type" errors={[]}>
            <Segmented
              id="step-coupon-type"
              shape="square"
              value={field.state.value}
              // Clicking the selected item would clear a single toggle group; keep a type chosen.
              onValueChange={(type) => type && field.handleChange(type as DiscountType)}
            >
              {Object.entries(discountTypeLabels).map(([type, label]) => (
                <SegmentedItem key={type} value={type}>
                  {label}
                </SegmentedItem>
              ))}
            </Segmented>
          </ConfigField>
        )}
      </form.Field>

      {discountType !== 'free_shipping' &&
        textField(
          'amount',
          discountType === 'percent'
            ? __('Amount (%)', 'yay-automation')
            : `${__('Amount', 'yay-automation')} (${currencySymbol})`,
        )}
      {textField(
        'expiry_days',
        __('Expires after (days)', 'yay-automation'),
        __('Leave blank for a coupon that never expires.', 'yay-automation'),
      )}
      {textField(
        'minimum_spend',
        `${__('Minimum spend', 'yay-automation')} (${currencySymbol})`,
        __('Leave blank for no minimum.', 'yay-automation'),
      )}
      {textField(
        'code_prefix',
        __('Code prefix', 'yay-automation'),
        __('Added before the random part of the code, e.g. THANKS-7K2P9Q.', 'yay-automation'),
        'THANKS-',
      )}
      {toggleField(
        'limit_to_customer',
        __("Limit to the customer's email", 'yay-automation'),
        __('Only the customer the workflow runs for can use it.', 'yay-automation'),
      )}
      {toggleField(
        'individual_use',
        __('Individual use only', 'yay-automation'),
        __("Can't be combined with other coupons.", 'yay-automation'),
      )}

      <p className="text-muted-foreground text-xs">
        {__('Each coupon can be used once. Use {{ coupon.code }} in a later email.', 'yay-automation')}
      </p>
    </FieldGroup>
  );
}
