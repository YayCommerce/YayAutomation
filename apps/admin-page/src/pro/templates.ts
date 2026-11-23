import type { WorkflowTemplate } from '@yaymatik/types';

import { cartSeries, cartSeriesCoupon } from './routes/workflows/templates/abandoned-cart';
import {
  crossSellCoupon,
  crossSellFirstTime,
  crossSellRepeat,
  upsellAfter30Days,
} from './routes/workflows/templates/cross-sell-upsell';
import { loginNoCart } from './routes/workflows/templates/login';
import { reviewRequestReminder } from './routes/workflows/templates/review-request';
import { welcomeSeries } from './routes/workflows/templates/welcome';
import { winBackDiscount, winBackFreeShipping } from './routes/workflows/templates/win-back';

/** Pro's templates, added to core's; none without a license, as their steps aren't registered then. */
export const templates: WorkflowTemplate[] = window.yayMatikPro.licensed
  ? [
      cartSeries,
      cartSeriesCoupon,
      crossSellFirstTime,
      crossSellRepeat,
      crossSellCoupon,
      upsellAfter30Days,
      reviewRequestReminder,
      welcomeSeries,
      winBackDiscount,
      winBackFreeShipping,
      loginNoCart,
    ]
  : [];
