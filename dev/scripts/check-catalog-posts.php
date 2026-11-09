<?php
/**
 * Loaded with `wp --require`: this process alone reads and writes orders on the posts store, without syncing,
 * so no site setting changes and WooCommerce needn't be in sync. The check's orders are removed from there.
 *
 * @package YayMatik
 */

define( 'YAYMATIK_CHECK_POSTS_STORE', true );
WP_CLI::add_wp_hook( 'pre_option_woocommerce_custom_orders_table_enabled', fn() => 'no' );
WP_CLI::add_wp_hook( 'pre_option_woocommerce_custom_orders_table_data_sync_enabled', fn() => 'no' );
