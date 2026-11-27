<?php
/**
 * Loads YayWholesale's scaffold: the extension on its fake adapter.
 *
 * @package YayWholesale
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/YayWholesale_YayMatik.php';
require_once __DIR__ . '/YayWholesale_YayMatik_Fake_Adapter.php';

add_action( 'yaymatik_register', [ new YayWholesale_YayMatik( new YayWholesale_YayMatik_Fake_Adapter() ), 'register' ] );
