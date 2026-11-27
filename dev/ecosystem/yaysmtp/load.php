<?php
/**
 * Loads YaySMTP's scaffold: the extension on its fake adapter.
 *
 * @package YaySMTP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/YaySMTP_YayMatik.php';
require_once __DIR__ . '/YaySMTP_YayMatik_Fake_Adapter.php';

add_action( 'yaymatik_register', [ new YaySMTP_YayMatik( new YaySMTP_YayMatik_Fake_Adapter() ), 'register' ] );
