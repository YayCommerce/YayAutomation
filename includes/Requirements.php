<?php
namespace YayMatik;

defined( 'ABSPATH' ) || exit;

// Loaded before the autoloader and checked on any PHP, so this file uses no typed parameters or newer syntax.

/** The WordPress, PHP and WooCommerce versions YayMatik needs, checked before any of its code loads. */
final class Requirements {

    const WP = '6.9';

    const PHP = '7.4';

    const WC = '11.0';

    /**
     * What is too old or missing, e.g. "WordPress 6.9"; empty when all are met.
     *
     * @param string      $wp
     * @param string      $php
     * @param string|null $wc null when WooCommerce isn't active.
     * @return string[]
     */
    public static function missing( $wp, $php, $wc ) {
        $missing = [];
        if ( version_compare( $wp, self::WP, '<' ) ) {
            $missing[] = 'WordPress ' . self::WP;
        }
        if ( version_compare( $php, self::PHP, '<' ) ) {
            $missing[] = 'PHP ' . self::PHP;
        }
        if ( null === $wc || version_compare( $wc, self::WC, '<' ) ) {
            $missing[] = 'WooCommerce ' . self::WC;
        }
        return $missing;
    }

    /** Whether this site meets them; if not, a notice says what is missing on the Plugins screen. */
    public static function met() {
        $missing = self::missing( $GLOBALS['wp_version'], PHP_VERSION, defined( 'WC_VERSION' ) ? WC_VERSION : null );
        if ( ! $missing ) {
            return true;
        }
        add_action(
            'admin_notices',
            function () use ( $missing ) {
                $screen = get_current_screen();
                if ( ! $screen || ! in_array( $screen->id, [ 'plugins', 'plugins-network' ], true ) || ! current_user_can( 'activate_plugins' ) ) {
                    return;
                }
                /* translators: %s: what is missing, e.g. "WordPress 6.9, WooCommerce 11.0" */
                $message = sprintf( __( 'YayMatik needs %s or later, so it is not running.', 'yaymatik' ), implode( ', ', $missing ) );
                echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
            }
        );
        return false;
    }
}
