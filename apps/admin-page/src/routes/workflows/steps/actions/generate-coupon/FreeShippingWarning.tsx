import { TriangleAlert } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Alert, AlertDescription } from '@/components/ui/alert';
import type { GenerateCouponConfig } from '.';

const { wc, wp } = window.yayMatikMeta;

/**
 * A free shipping coupon works only with a Free shipping method set to take one (PHP
 * `FreeShipping::coupon_mode()`): warn when none does, or when the only ones also need a minimum.
 */
export function FreeShippingWarning({ config }: { config: GenerateCouponConfig }) {
  if (config.discount_type !== 'free_shipping' || wc.freeShippingCoupon === 'coupon') return null;
  return (
    // Amber like the "Needs setup" badge; the soft tokens have light and dark values.
    <Alert className="bg-warning-soft [&>svg]:text-warning-soft-foreground">
      <TriangleAlert aria-hidden />
      <AlertDescription className="text-foreground">
        {wc.freeShippingCoupon === '' ? (
          <p>
            {__(
              'No shipping method takes a free shipping coupon yet, so this coupon won’t give free shipping. Add a Free shipping method that requires “A valid free shipping coupon” in',
              'yaymatik',
            )}{' '}
            <a
              href={`${wp.adminUrl}admin.php?page=wc-settings&tab=shipping`}
              target="_blank"
              rel="noreferrer"
              className="text-foreground underline"
            >
              {__('WooCommerce → Shipping', 'yaymatik')}
            </a>
            .
          </p>
        ) : (
          <p>
            {__(
              'Your Free shipping methods also need a minimum order amount, so the customer gets free shipping only when the order reaches it.',
              'yaymatik',
            )}
          </p>
        )}
      </AlertDescription>
    </Alert>
  );
}
