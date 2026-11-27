<?php
/**
 * Loads YayLoyalty's scaffold: the extension on its fake adapter.
 *
 * @package YayLoyalty
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/YayLoyalty_YayMatik.php';
require_once __DIR__ . '/YayLoyalty_YayMatik_Fake_Adapter.php';

add_action( 'yaymatik_register', [ new YayLoyalty_YayMatik( new YayLoyalty_YayMatik_Fake_Adapter() ), 'register' ] );
