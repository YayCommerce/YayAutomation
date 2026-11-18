<?php
/**
 * Steps of check-plugin-lifecycle.sh, one per call: `wp eval-file … <step>`. Loaded with --require instead, it makes
 * WordPress report 6.8 for that process.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.EscapeOutput.OutputNotEscaped -- a dev check on a wp-env site.

if ( ! isset( $args ) ) {
    // version.php is loaded before mu-plugins, so this runs after it and before YayMatik's check on plugins_loaded.
    WP_CLI::add_wp_hook(
        'muplugins_loaded',
        function () {
            $GLOBALS['wp_version'] = '6.8'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the point of the check.
        }
    );
    return;
}

global $wpdb;
$fails = 0;
$ok    = function ( $pass, $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n";
    $fails += $pass ? 0 : 1;
};

// The recurring jobs deactivation unschedules; names written out, since the plugin may not be loaded.
$recurring = [ 'yaymatik_cleanup', 'yaymatik_cart_sweep', 'yaymatik_inactive_sweep' ];
$pending   = function ( string $hook ) use ( $wpdb ) {
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND status = 'pending'", $hook ) );
};
$in_group  = function () use ( $wpdb ) {
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions a JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id WHERE g.slug = 'yaymatik'" );
};
$like      = function ( string $table, string $column, string $pattern ) use ( $wpdb ) {
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE %s", $pattern ) );
};
$tables    = function () use ( $wpdb ) {
    return count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'yaymatik_' ) . '%' ) ) );
};
$coupon_id = function () {
    return wc_get_coupon_id_by_code( 'yaymatik-lifecycle-check' );
};
$the_order = function () {
    return wc_get_orders(
        [
            'billing_email' => 'lifecycle@yay-seed.test',
            'limit'         => 1,
        ]
    )[0] ?? null;
};

$orders_meta = $wpdb->prefix . 'wc_orders_meta';
$hpos        = $orders_meta === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta ) );

switch ( $args[0] ?? '' ) {
    case 'old-wp':
        $notice = false;
        foreach ( $GLOBALS['wp_filter']['admin_notices']->callbacks ?? [] as $callbacks ) {
            foreach ( $callbacks as $callback ) {
                if ( $callback['function'] instanceof Closure && false !== strpos( ( new ReflectionFunction( $callback['function'] ) )->getFileName(), 'includes/Requirements.php' ) ) {
                    $notice = true;
                }
            }
        }
        $ok( '6.8' === $GLOBALS['wp_version'], 'WordPress reports 6.8 in this process' );
        $ok( $notice, 'the requirements notice is registered' );
        $ok( ! class_exists( 'YayMatik\YayMatik', false ), 'no YayMatik class is loaded' );
        $ok( ! class_exists( 'YayMatikScoped\YayCommerce\AdminShell\AdminShell', false ), 'the autoloader never ran (admin-shell not loaded)' );
        break;

    case 'second-copy':
        foreach ( $GLOBALS['wp_filter']['admin_init']->callbacks ?? [] as $callbacks ) {
            foreach ( $callbacks as $callback ) {
                if ( $callback['function'] instanceof Closure && preg_match( '#/plugins/yaymatik(-pro)?/yaymatik(-pro)?\.php$#', ( new ReflectionFunction( $callback['function'] ) )->getFileName() ) ) {
                    $callback['function']();
                }
            }
        }
        break;

    case 'schedule':
        delete_option( 'yaymatik_cleanup_checked_at' );
        \YayMatik\Workflow\Core\Runtime\Cleanup::ensure();
        foreach ( $recurring as $hook ) {
            $ok( 1 === $pending( $hook ), "{$hook} scheduled" );
        }
        break;

    case 'deactivated':
        foreach ( $recurring as $hook ) {
            $ok( 0 === $pending( $hook ), "deactivated: no pending {$hook}" );
        }
        $ok( false === get_option( 'yaymatik_cleanup_checked_at' ), 'deactivated: yaymatik_cleanup_checked_at gone' );
        break;

    case 'reactivated':
        // action_scheduler_init ran while this process loaded: Cleanup::ensure() found the option gone.
        $ok( did_action( 'action_scheduler_init' ) > 0, 'action_scheduler_init ran' );
        foreach ( $recurring as $hook ) {
            $ok( 1 === $pending( $hook ), "reactivated: {$hook} back" );
        }
        $ok( false !== get_option( 'yaymatik_cleanup_checked_at' ), 'reactivated: yaymatik_cleanup_checked_at set' );
        break;

    case 'prepare':
        as_schedule_single_action( time() + DAY_IN_SECONDS, 'yaymatik_run', [ 0 ], 'yaymatik' );
        set_transient( 'yaymatik_lifecycle_check', 1, HOUR_IN_SECONDS );
        if ( ! $coupon_id() ) {
            $coupon = new WC_Coupon();
            $coupon->set_code( 'yaymatik-lifecycle-check' );
            $coupon->update_meta_data( '_yaymatik_generated', '1' );
            $coupon->save();
        }
        if ( ! $the_order() ) {
            $new = wc_create_order();
            $new->set_billing_email( 'lifecycle@yay-seed.test' );
            $new->update_meta_data( '_yaymatik_lifecycle_check', '1' );
            $new->save();
        }
        $ok( $in_group() > 0 && $like( $wpdb->options, 'option_name', '\_transient\_yaymatik\_%' ) > 0, 'prepared: a job in the group and a transient' );
        $ok( 8 === $tables() && false !== get_option( 'yaymatik_db_version' ), 'prepared: 8 tables and the options' );
        $ok( $coupon_id() && '1' === get_post_meta( $coupon_id(), '_yaymatik_generated', true ) && $the_order(), 'prepared: a generated coupon and an order with YayMatik meta' );
        break;

    case 'uninstalled-kept':
        $ok( 0 === $in_group(), 'uninstalled: no job left in the yaymatik group' );
        $ok( 0 === $like( $wpdb->options, 'option_name', '\_transient\_yaymatik\_%' ) && 0 === $like( $wpdb->options, 'option_name', '\_transient\_timeout\_yaymatik\_%' ), 'uninstalled: no yaymatik transient' );
        $ok( 8 === $tables(), 'without the constant: the 8 tables stay' );
        $ok( false !== get_option( 'yaymatik_db_version' ) && $like( $wpdb->options, 'option_name', 'yaymatik\_%' ) > 0, 'without the constant: options stay' );
        $ok( '1' === get_post_meta( $coupon_id(), '_yaymatik_generated', true ), 'without the constant: coupon meta stays' );
        $ok( '1' === $the_order()->get_meta( '_yaymatik_lifecycle_check' ), 'without the constant: order meta stays' );
        break;

    case 'uninstalled-all':
        wp_cache_flush();
        $ok( 0 === $in_group(), 'uninstalled: no job left in the yaymatik group' );
        $ok( 0 === $tables(), 'with the constant: every yaymatik table dropped' );
        $ok( 0 === $like( $wpdb->options, 'option_name', 'yaymatik\_%' ), 'with the constant: no yaymatik option' );
        $ok( 0 === $like( $wpdb->postmeta, 'meta_key', '\_yaymatik\_%' ), 'with the constant: no _yaymatik_ post meta' );
        $ok( ! $hpos || 0 === $like( $orders_meta, 'meta_key', '\_yaymatik\_%' ), 'with the constant: no _yaymatik_ order meta' . ( $hpos ? '' : ' (posts store, in post meta)' ) );
        $ok( $coupon_id() > 0 && 'shop_coupon' === get_post_type( $coupon_id() ), 'with the constant: the generated coupon stays' );
        break;

    case 'cleanup':
        // By id afterwards: WooCommerce caches the coupon id of a code for the rest of the process.
        $coupon   = $coupon_id();
        $order_id = $the_order() ? $the_order()->get_id() : 0;
        if ( $coupon ) {
            wp_delete_post( $coupon, true );
        }
        if ( $order_id ) {
            wc_get_order( $order_id )->delete( true );
        }
        $ok( ( ! $coupon || ! get_post( $coupon ) ) && ( ! $order_id || ! wc_get_order( $order_id ) ), 'test coupon and order removed' );
        break;

    default:
        WP_CLI::error( 'Unknown step: ' . ( $args[0] ?? '' ) );
}

if ( $fails ) {
    WP_CLI::halt( 1 );
}
