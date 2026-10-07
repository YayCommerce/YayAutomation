import { useForm, useStore } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldContent, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { ConfigField } from '../../core/fields/ConfigField';
import { useMarkInvalid } from '../../core/fields/feedback';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import type { BlockFormProps } from '../../core/types';
import { schema, type DiscountType, type GenerateCouponConfig } from './schema';

const { currencySymbol } = window.yayAutomationMeta.wc;

export const discountTypeLabels: Record<DiscountType, string> = {
  percent: __('Percentage', 'yay-automation'),
  fixed_cart: __('Fixed amount', 'yay-automation'),
  free_shipping: __('Free shipping', 'yay-automation'),
};

type TextKey = 'amount' | 'expiry_days' | 'minimum_spend' | 'code_prefix';
type CheckKey = 'individual_use' | 'limit_to_customer';

export function GenerateCouponForm({ config, onChange }: BlockFormProps<GenerateCouponConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();
  const discountType = useStore(form.store, (state) => state.values.discount_type);

  const textField = (name: TextKey, label: string, description?: string, placeholder?: string) => (
    <form.Field name={name}>
      {(field) => (
        <ConfigField
          label={label}
          htmlFor={`block-coupon-${name}`}
          description={description}
          errors={field.state.meta.errors}
        >
          <Input
            id={`block-coupon-${name}`}
            value={field.state.value}
            placeholder={placeholder}
            inputMode={name === 'code_prefix' ? undefined : 'decimal'}
            onChange={(event) => field.handleChange(event.target.value)}
            onBlur={field.handleBlur}
            aria-invalid={markInvalid(field.state.meta)}
          />
        </ConfigField>
      )}
    </form.Field>
  );

  const checkField = (name: CheckKey, label: string, description: string) => (
    <form.Field name={name}>
      {(field) => (
        <Field orientation="horizontal">
          <Checkbox
            id={`block-coupon-${name}`}
            checked={field.state.value}
            onCheckedChange={(checked) => field.handleChange(checked === true)}
          />
          <FieldContent>
            <FieldLabel htmlFor={`block-coupon-${name}`}>{label}</FieldLabel>
            <FieldDescription>{description}</FieldDescription>
          </FieldContent>
        </Field>
      )}
    </form.Field>
  );

  return (
    <FieldGroup>
      <form.Field name="discount_type">
        {(field) => (
          <ConfigField label={__('Discount type', 'yay-automation')} htmlFor="block-coupon-type" errors={[]}>
            <Segmented
              id="block-coupon-type"
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
      {checkField(
        'limit_to_customer',
        __("Limit to the customer's email", 'yay-automation'),
        __('Only the customer the workflow runs for can use it.', 'yay-automation'),
      )}
      {checkField(
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
