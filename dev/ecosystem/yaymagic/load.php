<?php
/**
 * Loads YayMagic's scaffold: the extension on its fake adapter.
 *
 * @package YayMagic
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/YayMagic_YayMatik.php';
require_once __DIR__ . '/YayMagic_YayMatik_Fake_Adapter.php';

$yaymagic_adapter = new YayMagic_YayMatik_Fake_Adapter();
$yaymagic_adapter->hook_mail();
$yaymagic_adapter->hook_editor();
add_action( 'yaymatik_register', [ new YayMagic_YayMatik( $yaymagic_adapter ), 'register' ] );
