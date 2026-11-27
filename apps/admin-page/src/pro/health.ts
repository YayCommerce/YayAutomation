import { __ } from '@wordpress/i18n';

/** PHP `Pro\Boot::boot()` adds the inactive-customer sweep to the daily jobs. */
export const healthJobs = window.yayMatikPro.licensed
  ? {
      inactive_sweep_job: {
        title: () =>
          __('The daily inactive-customer check isn’t running on time', 'yaymatik-automation-for-woocommerce'),
        help: () =>
          __(
            'It’s missing or more than a day late, so customers may start their win-back late or miss it.',
            'yaymatik-automation-for-woocommerce',
          ),
        fixed: () => __('The daily inactive-customer check is scheduled again.', 'yaymatik-automation-for-woocommerce'),
      },
    }
  : {};
