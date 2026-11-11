<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * @package yaycommerce/yaymatik
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Another YayMatik copy is active (this one is a duplicate being deleted): the data is still in use.
if ( defined( 'YAYMATIK_FILE' ) ) {
    return;
}

require_once __DIR__ . '/includes/Uninstaller.php';
YayMatik\Uninstaller::uninstall();
