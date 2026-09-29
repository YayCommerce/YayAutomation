import { useForm } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

import { FieldGroup } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ConfigField } from '../../core/fields/ConfigField';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import type { BlockFormProps } from '../../core/types';
import { currencySymbol, operatorLabels, operators, schema, type Operator, type OrderTotalConfig } from './schema';

export function OrderTotalForm({ config, onChange }: BlockFormProps<OrderTotalConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);

  return (
    <FieldGroup>
      <form.Field name="operator">
        {(field) => (
          <ConfigField
            label={__('Order total', 'yay-automation')}
            htmlFor="block-total-operator"
            errors={field.state.meta.errors}
          >
            <Select value={field.state.value} onValueChange={(value) => field.handleChange(value as Operator)}>
              <SelectTrigger id="block-total-operator" aria-invalid={!field.state.meta.isValid}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {operators.map((operator) => (
                  <SelectItem key={operator} value={operator}>
                    {operatorLabels[operator]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </ConfigField>
        )}
      </form.Field>

      <form.Field name="amount">
        {(field) => (
          <ConfigField
            label={__('Amount', 'yay-automation')}
            htmlFor="block-total-amount"
            description={__('Compared with the order total, including tax and shipping.', 'yay-automation')}
            errors={field.state.meta.errors}
          >
            <InputGroup>
              {currencySymbol && <InputGroupAddon>{currencySymbol}</InputGroupAddon>}
              <InputGroupInput
                id="block-total-amount"
                type="number"
                inputMode="decimal"
                min={0}
                step="any"
                // Empty stays null (unconfigured) rather than silently becoming 0.
                value={field.state.value ?? ''}
                onChange={(event) => field.handleChange(event.target.value === '' ? null : Number(event.target.value))}
                onBlur={field.handleBlur}
                aria-invalid={!field.state.meta.isValid}
              />
            </InputGroup>
          </ConfigField>
        )}
      </form.Field>
    </FieldGroup>
  );
}
