<?php
/**
 * Loads YayMagic's scaffold: the extension on its fake adapter.
 *
 * @package YayMagic
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/YayMagic_YayMatik.php';
require_once __DIR__ . '/YayMagic_YayMatik_Fake_Adapter.php';

add_action( 'yaymatik_register', [ new YayMagic_YayMatik( new YayMagic_YayMatik_Fake_Adapter() ), 'register' ] );
