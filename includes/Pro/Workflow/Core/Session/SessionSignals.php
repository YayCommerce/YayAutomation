<?php
namespace YayMatik\Pro\Workflow\Core\Session;

defined( 'ABSPATH' ) || exit;

/**
 * The login triggers' one set of hooks: marks a new session at sign-in and confirms it on the person's next request;
 * a storefront visit (or the beacon) after a long absence is a return. Each tells every subscriber. Hooks are added
 * only once something subscribes.
 */
final class SessionSignals {

    /** Session keys of the mark. */
    public const PENDING = 'yaymatik_pending';
    public const HOW     = 'yaymatik_how';

    /** A visit after this long without one is a return. */
    public const RETURN_AFTER = 12 * HOUR_IN_SECONDS;

    /** Last seen is written at most this often while the person browses on. */
    public const REFRESH = 15 * MINUTE_IN_SECONDS;

    /** @var self|null */
    private static $instance = null;

    /** @var callable[] fn( array $args ): void, each trigger's `$emit`. */
    private $subscribers = [];

    /** @var true[] User id => registered in this request. */
    private $registered = [];

    /** @var int[] Session token made in this request => its user id. */
    private $created = [];

    /** @var int|null The user this request was signed in as on `init`; null before it. */
    private $started_as = null;

    public static function instance(): self {
        self::$instance = self::$instance ?? new self();
        return self::$instance;
    }

    /**
     * @param callable $emit fn( array $args ): void; args `user_id`, `how` (`sign_in`, `auto`, `returned`), `time`.
     */
    public function subscribe( callable $emit ): void {
        if ( ! $this->subscribers ) {
            $this->hook();
        }
        $this->subscribers[] = $emit;
    }

    public function subscribed(): bool {
        return (bool) $this->subscribers;
    }

    private function hook(): void {
        // Late, so session data other plugins add (User Switching's `switched_from_id`) is already there.
        add_filter( 'attach_session_information', [ $this, 'mark' ], PHP_INT_MAX - 10, 2 );
        add_action( 'set_auth_cookie', [ $this, 'created' ], 10, 6 );
        add_action( 'user_register', [ $this, 'registered' ] );
        add_action( 'switch_to_user', [ $this, 'unmark_created' ] );
        add_action( 'switch_back_user', [ $this, 'unmark_created' ] );
        // After the Dispatcher's `init` priority 0, which subscribes.
        add_action( 'init', [ $this, 'confirm' ], 1 );
        add_action( 'template_redirect', [ $this, 'storefront' ] );
    }

    /**
     * On `attach_session_information`: marks a session made by signing in, or by the automatic sign-in after sign-up.
     *
     * @param array $session
     * @param int   $user_id
     * @return array
     */
    public function mark( $session, $user_id ) {
        $session = (array) $session;
        $user_id = (int) $user_id;
        $auto    = isset( $this->registered[ $user_id ] );
        // A new session for the person this request was already signed in as reissues the cookie (WooCommerce's
        // password reset does): not a sign-in.
        if ( isset( $session['switched_from_id'] ) || ( ! $auto && $this->started_as === $user_id ) ) {
            return $session;
        }
        $session[ self::PENDING ] = 1;
        $session[ self::HOW ]     = $auto ? 'auto' : 'sign_in';
        return $session;
    }

    /**
     * On `set_auth_cookie`: a session made in this request is never confirmed in it.
     *
     * @param string $auth_cookie
     * @param int    $expire
     * @param int    $expiration
     * @param int    $user_id
     * @param string $scheme
     * @param string $token
     */
    public function created( $auth_cookie, $expire, $expiration, $user_id, $scheme, $token ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- the hook's arguments.
        $this->created[ (string) $token ] = (int) $user_id;
    }

    /**
     * @param int $user_id
     */
    public function registered( $user_id ): void {
        $this->registered[ (int) $user_id ] = true;
    }

    /** On a User Switching switch: the sessions made in this request aren't anyone signing in. */
    public function unmark_created(): void {
        foreach ( $this->created as $token => $user_id ) {
            $manager = \WP_Session_Tokens::get_instance( $user_id );
            $session = $manager->get( (string) $token );
            if ( is_array( $session ) && isset( $session[ self::PENDING ] ) ) {
                unset( $session[ self::PENDING ], $session[ self::HOW ] );
                $manager->update( (string) $token, $session );
            }
        }
    }

    /** On `init`: the first later request of a marked session confirms the sign-in. */
    public function confirm(): void {
        $user_id          = get_current_user_id();
        $this->started_as = $user_id;
        $token            = wp_get_session_token();
        if ( ! $user_id || '' === $token || isset( $this->created[ $token ] ) ) {
            return;
        }
        $manager = \WP_Session_Tokens::get_instance( $user_id );
        $session = $manager->get( $token );
        if ( ! is_array( $session ) || empty( $session[ self::PENDING ] ) ) {
            return;
        }
        $how = 'auto' === ( $session[ self::HOW ] ?? '' ) ? 'auto' : 'sign_in';
        unset( $session[ self::PENDING ], $session[ self::HOW ] );
        $manager->update( $token, $session );
        $now = time();
        LastSeen::set( $user_id, $now );
        $this->emit( $user_id, $how, $now );
    }

    /** On `template_redirect`: a page a person opens, not a feed, a preview, a HEAD request or a prefetch. */
    public function storefront(): void {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
        $head     = 'HEAD' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
        $purpose  = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- read with a default.
        $prefetch = false !== strpos( $purpose, 'prefetch' );
        if ( $head || $prefetch || is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
        $this->visit( get_current_user_id() );
    }

    /**
     * A signed-in person is on the store: the first visit after `RETURN_AFTER` is a return. Only the request whose write moved
     * last seen tells the subscribers, so parallel requests (two tabs) make one event.
     *
     * @param int $user_id
     */
    public function visit( int $user_id ): void {
        if ( ! $this->subscribers || ! $user_id ) {
            return;
        }
        $token = wp_get_session_token();
        if ( isset( $this->created[ $token ] ) ) {
            return;
        }
        $session = '' === $token ? null : \WP_Session_Tokens::get_instance( $user_id )->get( $token );
        if ( ! empty( $session[ self::PENDING ] ) ) {
            return;
        }
        $now  = time();
        $seen = LastSeen::get( $user_id );
        if ( null === $seen ) {
            LastSeen::set( $user_id, $now );
            return;
        }
        if ( $now - $seen >= self::RETURN_AFTER ) {
            if ( LastSeen::advance( $user_id, $now, self::RETURN_AFTER ) ) {
                $this->emit( $user_id, 'returned', $now );
            }
            return;
        }
        LastSeen::advance( $user_id, $now, self::REFRESH );
    }

    /**
     * @param int    $user_id
     * @param string $how
     * @param int    $now
     */
    private function emit( int $user_id, string $how, int $now ): void {
        foreach ( $this->subscribers as $emit ) {
            $emit(
                [
                    'user_id' => $user_id,
                    'how'     => $how,
                    'time'    => $now,
                ]
            );
        }
    }
}
