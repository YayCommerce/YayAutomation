<?php
/**
 * Fake store data: cash on delivery, products, one customer, cart tracking on. Safe to run again. Run through seed.sh.
 *
 * @package YayMatik
 */

use YayMatik\Workflow\Core\Storage\Settings;

if ( ! function_exists( 'WC' ) ) {
    WP_CLI::error( 'WooCommerce is not active.' );
}
if ( ! class_exists( Settings::class ) ) {
    WP_CLI::error( 'YayMatik is not active. Install it first (on staging: ./run.sh deploy).' );
}

update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_cod_settings', array_merge( (array) get_option( 'woocommerce_cod_settings', [] ), [ 'enabled' => 'yes' ] ) );
// wp eval doesn't load these, and without them the flush skips .htaccess, so an Apache site would 404.
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
// Not update_option: $wp_rewrite keeps the old structure, and the flush would store its rules.
$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
flush_rewrite_rules();
\WC_Install::create_pages();

foreach ( [
    'yay-seed-tee'   => [ 'Seed Tee', '20' ],
    'yay-seed-mug'   => [ 'Seed Mug', '12' ],
    'yay-seed-print' => [ 'Seed Print', '150' ],
] as $sku => [ $name, $price ] ) {
    if ( wc_get_product_id_by_sku( $sku ) ) {
        continue;
    }
    $product = new \WC_Product_Simple();
    $product->set_props(
        [
            'name'          => $name,
            'sku'           => $sku,
            'regular_price' => $price,
            'status'        => 'publish',
            'virtual'       => true,
        ]
    );
    $product->save();
}

$customer_email = 'customer@yay-seed.test';
if ( ! email_exists( $customer_email ) ) {
    $customer = wc_create_new_customer( $customer_email, 'yay-seed-customer', 'yay-seed-customer' );
    if ( is_wp_error( $customer ) ) {
        WP_CLI::error( $customer );
    }
}

$updated = Settings::update( [ 'cart_tracking' => true ] );
if ( is_wp_error( $updated ) ) {
    WP_CLI::error( $updated );
}

WP_CLI::success( 'Seeded. Customer login: yay-seed-customer / yay-seed-customer' );
