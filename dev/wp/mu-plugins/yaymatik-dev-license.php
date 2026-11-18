<?php
/**
 * Plugin Name: YayMatik dev license
 * Description: A YayMatik Pro license for wp-env, so the checkout runs as Pro. `wp option update yaymatik_dev_edition lite` runs it as the free edition.
 *
 * @package YayMatik
 */

defined( 'ABSPATH' ) || exit;

// Filters, not stored options: admin-shell's daily license check would remove a key the store doesn't know.
add_filter( 'pre_option_yaymatik-pro_license_key', fn() => 'lite' === get_option( 'yaymatik_dev_edition' ) ? '' : 'wp-env-dev-key' );
add_filter(
    'pre_option_yaymatik-pro_license_info',
    fn() => 'lite' === get_option( 'yaymatik_dev_edition' ) ? [] : [
        'license' => 'valid',
        'expires' => 'lifetime',
    ]
);
