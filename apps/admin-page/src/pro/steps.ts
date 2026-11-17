import type { Step } from '@/routes/workflows/steps/core/types';
import { changeCustomerRoleStep } from './routes/workflows/steps/actions/change-customer-role';
import { resendWcEmailStep } from './routes/workflows/steps/actions/resend-wc-email';
import { updateCustomerMetaStep } from './routes/workflows/steps/actions/update-customer-meta';
import { updateOrderMetaStep } from './routes/workflows/steps/actions/update-order-meta';
import { waitUntilStep } from './routes/workflows/steps/actions/wait-until';
import { customerInactiveStep } from './routes/workflows/steps/triggers/customer-inactive';
import { customerOrderCountReachesStep } from './routes/workflows/steps/triggers/customer-order-count-reaches';
import { customerTotalSpendReachesStep } from './routes/workflows/steps/triggers/customer-total-spend-reaches';

/** Pro's steps, added to core's. Built only with a license: without one PHP's catalog has none of them. */
export const steps: Step[] = window.yayMatikPro.licensed
  ? [
      customerInactiveStep(),
      customerOrderCountReachesStep(),
      customerTotalSpendReachesStep(),
      resendWcEmailStep(),
      updateOrderMetaStep(),
      updateCustomerMetaStep(),
      changeCustomerRoleStep(),
      waitUntilStep(),
    ]
  : [];
