<?php
namespace YayMatik\Workflow\Core\Cart;

use YayMatik\Workflow\Core\Runtime\Clock;

defined( 'ABSPATH' ) || exit;

/** Rate limits on email capture: per session, per IP and site-wide, in fixed windows kept in transients. */
final class CaptureLimits {

    /** Name => [ max, window seconds ]. */
    private const LIMITS = [
        'session' => [ 10, 10 * MINUTE_IN_SECONDS ],
        'ip'      => [ 20, 15 * MINUTE_IN_SECONDS ],
        'site'    => [ 500, 15 * MINUTE_IN_SECONDS ],
    ];

    /** @var string */
    private $secret;

    /** @var Clock */
    private $clock;

    /**
     * @param string $secret Keys the IP hash, so no plain IP is stored.
     * @param Clock  $clock
     */
    public function __construct( string $secret, Clock $clock ) {
        $this->secret = $secret;
        $this->clock  = $clock;
    }

    /**
     * Counts one capture unless a limit is reached; a refused one counts nowhere.
     *
     * @param string $session WooCommerce session key.
     * @param string $ip
     */
    public function allow( string $session, string $ip ): bool {
        $keys    = [
            'session' => 'yaymatik_cap_s_' . md5( $session ),
            // The secret also signs restore links: the prefix keeps a client-chosen IP header from matching one.
            'ip'      => 'yaymatik_cap_i_' . substr( hash_hmac( 'sha256', 'ip|' . $ip, $this->secret ), 0, 32 ),
            'site'    => 'yaymatik_cap_site',
        ];
        $now     = $this->clock->now();
        $windows = [];
        foreach ( $keys as $name => $key ) {
            list( $max, $seconds ) = self::LIMITS[ $name ];
            $window                = get_transient( $key );
            if ( ! is_array( $window ) || $now >= (int) ( $window['until'] ?? 0 ) ) {
                $window = [
                    'count' => 0,
                    'until' => $now + $seconds,
                ];
            }
            if ( (int) $window['count'] >= $max ) {
                return false;
            }
            $windows[ $key ] = $window;
        }
        foreach ( $windows as $key => $window ) {
            ++$window['count'];
            set_transient( $key, $window, max( 1, $window['until'] - $now ) );
        }
        return true;
    }
}
