<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * @package yaycommerce/yaymatik
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Uninstaller.php';
YayMatik\Uninstaller::uninstall();
