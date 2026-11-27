<?php
/**
 * Loads YayMail's scaffold: the extension on its fake adapter.
 *
 * @package YayMail
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/YayMail_YayMatik.php';
require_once __DIR__ . '/YayMail_YayMatik_Fake_Adapter.php';

$yaymail_adapter = new YayMail_YayMatik_Fake_Adapter();
$yaymail_adapter->hook_mail();
add_action( 'yaymatik_register', [ new YayMail_YayMatik( $yaymail_adapter ), 'register' ] );
