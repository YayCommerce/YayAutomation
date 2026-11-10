<?php

defined( 'ABSPATH' ) || exit;
$yaymatik_duplicate_notice = function () {
    $screen = get_current_screen();
    if ( $screen && in_array( $screen->id, [ 'plugins', 'plugins-network' ], true ) && current_user_can( 'activate_plugins' ) ) {
        ?>
            <div class="notice notice-error is-dismissible">
            <p>
                <strong><?php esc_html_e( 'It looks like you have another YayMatik version installed, please delete it before activating this new version. All of the settings and data are still preserved.', 'yaymatik' ); ?>
                <a href="https://docs.yaycommerce.com/"><?php esc_html_e( 'Read more details.', 'yaymatik' ); ?></a>
                </strong>
            </p>
            </div>
        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['activate'] ) ) {
            unset( $_GET['activate'] );
        }
    }
};
// The network Plugins screen fires its own hook.
add_action( 'admin_notices', $yaymatik_duplicate_notice );
add_action( 'network_admin_notices', $yaymatik_duplicate_notice );
