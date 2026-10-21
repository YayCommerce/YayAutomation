import { createTemplateRegistry } from './core/registry';
import { crossSellCoupon, crossSellFirstTime, crossSellRepeat, upsellAfter30Days } from './cross-sell-upsell';
import { firstOrderDiscount, firstOrderFreeShipping } from './first-order-thank-you';
import highValueOrderAlert from './high-value-order-alert';

export { categoryLabels, isComingSoon, sendsCustomerEmail, workflowRank } from './core/meta';
export { pluginsOf } from './core/plugins';

/**
 * Every template in the library. To add one: create its folder (named after the template id) with an
 * `index.ts` that default-exports `defineTemplate({...})`, and list it here. Versions of one workflow
 * share a folder, which exports each one by name.
 */
export const { templates, findTemplate, versionsOf } = createTemplateRegistry([
  highValueOrderAlert,
  firstOrderDiscount,
  firstOrderFreeShipping,
  crossSellFirstTime,
  crossSellRepeat,
  crossSellCoupon,
  upsellAfter30Days,
]);
