import { useForm } from '@tanstack/react-form';
import * as v from 'valibot';
import { __, sprintf } from '@wordpress/i18n';

import { FieldGroup } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ConfigField, defineBlock, useValidateOnMount, type BlockFormProps } from './block';

const operators = ['gt', 'gte', 'lt', 'lte'] as const;
type Operator = (typeof operators)[number];

/** Mirrors src/Engine/Blocks/OrderTotal.php. `amount` is null until the user enters one. */
type OrderTotalConfig = { operator: Operator; amount: number | null };

const operatorMeta: Record<Operator, { label: string; symbol: string }> = {
  gt: { label: __('is more than', 'yay-automation'), symbol: '>' },
  gte: { label: __('is at least', 'yay-automation'), symbol: '≥' },
  lt: { label: __('is less than', 'yay-automation'), symbol: '<' },
  lte: { label: __('is at most', 'yay-automation'), symbol: '≤' },
};

const currencySymbol = window.yayAutomationMeta.wc.currencySymbol;
const amountError = __('Enter an amount of 0 or more.', 'yay-automation');

const schema = v.object({
  operator: v.picklist(operators, __('Choose a comparison.', 'yay-automation')),
  amount: v.pipe(
    v.nullable(v.number(amountError)),
    v.check((amount) => amount !== null && amount >= 0, amountError),
  ),
});

function OrderTotalForm({ config, onChange }: BlockFormProps<OrderTotalConfig>) {
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
                    {operatorMeta[operator].label}
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

export const orderTotalBlock = defineBlock<OrderTotalConfig>({
  id: 'order_total',
  kind: 'condition',
  label: __('Order total', 'yay-automation'),
  description: __(
    'Continues on the “Then” branch when the order total matches, otherwise on the “Otherwise” branch.',
    'yay-automation',
  ),
  schema,
  defaults: () => ({ operator: 'gt', amount: null }),
  summary: ({ operator, amount }) =>
    /* translators: 1: comparison symbol such as >, 2: currency symbol, 3: amount */
    sprintf(
      __('Order total %1$s %2$s%3$s', 'yay-automation'),
      operatorMeta[operator].symbol,
      currencySymbol,
      String(amount),
    ),
  Form: OrderTotalForm,
});
