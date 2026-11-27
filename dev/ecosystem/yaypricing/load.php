<?php
/**
 * Loads YayPricing's scaffold: the extension on its fake adapter.
 *
 * @package YayPricing
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/YayPricing_YayMatik.php';
require_once __DIR__ . '/YayPricing_YayMatik_Fake_Adapter.php';

add_action( 'yaymatik_register', [ new YayPricing_YayMatik( new YayPricing_YayMatik_Fake_Adapter() ), 'register' ] );
