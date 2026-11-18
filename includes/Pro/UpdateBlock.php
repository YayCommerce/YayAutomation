<?php
namespace YayMatik\Pro;

defined( 'ABSPATH' ) || exit;

/**
 * Hides what the updater fetches for this plugin while its item id is a stand-in: admin-shell registers the updater
 * on every admin request, so the stand-in item's package would be offered as an update and as "View details".
 */
final class UpdateBlock {

    /** @var mixed What `plugins_api` held before the updater answered. */
    private static $before = false;

    public static function register(): void {
        add_filter( 'site_transient_update_plugins', [ self::class, 'transient' ] );
        add_filter( 'plugins_api', [ self::class, 'remember' ], 9, 3 );
        add_filter( 'plugins_api', [ self::class, 'restore' ], 11, 3 );
    }

    /**
     * Moves this plugin from `response` to `no_update`, so no Update button or auto-update.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function transient( $value ) {
        if ( is_object( $value ) && isset( $value->response[ YAYMATIK_BASE_NAME ] ) ) {
            $value->no_update[ YAYMATIK_BASE_NAME ] = $value->response[ YAYMATIK_BASE_NAME ];
            unset( $value->response[ YAYMATIK_BASE_NAME ] );
        }
        return $value;
    }

    /**
     * @param mixed  $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public static function remember( $result, $action, $args ) {
        if ( self::ours( $args ) ) {
            self::$before = $result;
        }
        return $result;
    }

    /**
     * @param mixed  $result
     * @param string $action
     * @param object $args
     * @return mixed The value from before the updater's filter, for this plugin.
     */
    public static function restore( $result, $action, $args ) {
        return self::ours( $args ) ? self::$before : $result;
    }

    /** @param object $args */
    private static function ours( $args ): bool {
        // The updater's slug: the main file's name.
        return is_object( $args ) && isset( $args->slug ) && basename( YAYMATIK_FILE, '.php' ) === $args->slug;
    }
}
