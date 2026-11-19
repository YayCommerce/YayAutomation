<?php
namespace YayMatik\Pro\Engine\Privacy;

use YayMatik\Pro\Workflow\Core\Cart\CartActivity;
use YayMatik\Pro\Workflow\Core\Session\LastSeen;

defined( 'ABSPATH' ) || exit;

/** WordPress's personal data exporter and eraser for what YayMatik Pro keeps on an account: last seen, last added to cart. */
final class ActivityData {

    private const KEY = 'yaymatik-pro';

    public static function register(): void {
        add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'exporters' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'erasers' ] );
    }

    /**
     * @param array $exporters
     */
    public static function exporters( $exporters ): array {
        $exporters              = (array) $exporters;
        $exporters[ self::KEY ] = [
            'exporter_friendly_name' => __( 'YayMatik Pro', 'yaymatik' ),
            'callback'               => [ self::class, 'export' ],
        ];
        return $exporters;
    }

    /**
     * @param array $erasers
     */
    public static function erasers( $erasers ): array {
        $erasers              = (array) $erasers;
        $erasers[ self::KEY ] = [
            'eraser_friendly_name' => __( 'YayMatik Pro', 'yaymatik' ),
            'callback'             => [ self::class, 'erase' ],
        ];
        return $erasers;
    }

    /**
     * @param string $email
     */
    public static function export( $email ): array {
        $user = get_user_by( 'email', (string) $email );
        $data = [];
        foreach ( $user ? self::metas() : [] as $key => $label ) {
            $at = (int) get_user_meta( $user->ID, $key, true );
            if ( $at > 0 ) {
                $data[] = [
                    'name'  => $label,
                    'value' => wp_date( 'Y-m-d H:i:s', $at ),
                ];
            }
        }
        return [
            'data' => $data ? [
                [
                    'group_id'    => self::KEY,
                    'group_label' => __( 'YayMatik Pro', 'yaymatik' ),
                    'item_id'     => 'activity-' . $user->ID,
                    'data'        => $data,
                ],
            ] : [],
            'done' => true,
        ];
    }

    /**
     * @param string $email
     */
    public static function erase( $email ): array {
        $user    = get_user_by( 'email', (string) $email );
        $removed = false;
        foreach ( $user ? array_keys( self::metas() ) : [] as $key ) {
            $removed = delete_user_meta( $user->ID, $key ) || $removed;
        }
        return [
            'items_removed'  => $removed,
            'items_retained' => false,
            'messages'       => [],
            'done'           => true,
        ];
    }

    /** User meta key => its label in an export. */
    private static function metas(): array {
        return [
            LastSeen::KEY      => __( 'Last seen on the store', 'yaymatik' ),
            CartActivity::META => __( 'Last added to cart', 'yaymatik' ),
        ];
    }
}
