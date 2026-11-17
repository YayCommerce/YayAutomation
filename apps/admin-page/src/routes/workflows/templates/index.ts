import { templates as editionTemplates } from '@edition/templates';

import { cartReminder } from './abandoned-cart';
import { checkoutReminder, checkoutReminderCoupon } from './abandoned-checkout';
import { createTemplateRegistry } from './core/registry';
import { firstOrderDiscount, firstOrderFreeShipping } from './first-order-thank-you';
import highValueOrderAlert from './high-value-order-alert';
import { reviewRequest } from './review-request';
import { welcomeEmail } from './welcome';

export { categoryLabels, needsCartTracking, needsProductReviews, rankOf, sendsCustomerEmail } from './core/meta';
export { pluginsOf } from './core/plugins';

/**
 * Every template in the library: core's and the edition's, then other plugins' from PHP (`automation.templates`,
 * checked on the server). To add a core one: create its folder (named after the template id) with an `index.ts`
 * that default-exports `defineTemplate({...})`, and list it here. Versions of one workflow share a folder,
 * which exports each one by name; a Pro version goes in the same folder under `src/pro/`.
 */
export const { templates, findTemplate, versionsOf } = createTemplateRegistry(
  [
    cartReminder,
    checkoutReminder,
    checkoutReminderCoupon,
    highValueOrderAlert,
    firstOrderDiscount,
    firstOrderFreeShipping,
    reviewRequest,
    welcomeEmail,
    ...editionTemplates,
  ],
  window.yayMatikMeta.automation.templates,
);
