import { cartReminder, cartSeries, cartSeriesCoupon } from './abandoned-cart';
import { checkoutReminder, checkoutReminderCoupon } from './abandoned-checkout';
import { createTemplateRegistry } from './core/registry';
import { crossSellCoupon, crossSellFirstTime, crossSellRepeat, upsellAfter30Days } from './cross-sell-upsell';
import { firstOrderDiscount, firstOrderFreeShipping } from './first-order-thank-you';
import highValueOrderAlert from './high-value-order-alert';
import { reviewRequest, reviewRequestReminder } from './review-request';
import { welcomeEmail, welcomeSeries } from './welcome';
import { winBackDiscount, winBackFreeShipping } from './win-back';

export { categoryLabels, needsCartTracking, needsProductReviews, rankOf, sendsCustomerEmail } from './core/meta';
export { pluginsOf } from './core/plugins';

/**
 * Every template in the library: core's, then other plugins' from PHP (`automation.templates`, checked on
 * the server). To add a core one: create its folder (named after the template id) with an `index.ts` that
 * default-exports `defineTemplate({...})`, and list it here. Versions of one workflow share a folder,
 * which exports each one by name.
 */
export const { templates, findTemplate, versionsOf } = createTemplateRegistry(
  [
    cartReminder,
    cartSeries,
    cartSeriesCoupon,
    checkoutReminder,
    checkoutReminderCoupon,
    highValueOrderAlert,
    firstOrderDiscount,
    firstOrderFreeShipping,
    crossSellFirstTime,
    crossSellRepeat,
    crossSellCoupon,
    upsellAfter30Days,
    reviewRequest,
    reviewRequestReminder,
    welcomeEmail,
    welcomeSeries,
    winBackDiscount,
    winBackFreeShipping,
  ],
  window.yayMatikMeta.automation.templates,
);
