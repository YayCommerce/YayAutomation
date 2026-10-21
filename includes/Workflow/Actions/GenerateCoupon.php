<?php
namespace YayMatik\Workflow\Actions;

use YayMatik\Workflow\Coupon\CouponWriter;
use YayMatik\Workflow\Coupon\WcCouponWriter;
use YayMatik\Workflow\Data\CustomerData;
use YayMatik\Workflow\Data\Scope;
use YayMatik\Workflow\Data\Type;
use YayMatik\Workflow\Steps\Action;
use YayMatik\Workflow\Steps\Field;
use YayMatik\Workflow\Steps\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * A single-use WooCommerce coupon for later steps: `{{ steps.<id>.code }}`, `amount`, `expires_at`,
 * and `box` (a ready-made block for emails).
 *
 * Config: [ 'discount_type' => 'percent'|'fixed_cart'|'free_shipping', 'amount' => number|null
 * (unused for free shipping), 'expiry_days' => int|null (null = never), 'minimum_spend' =>
 * number|null, 'individual_use' => bool, 'limit_to_customer' => bool, 'code_prefix' => A–Z 0–9 - _ ].
 *
 * The code is the prefix and 8 random characters. A coupon expires at the end of its last day in the
 * store's timezone. The Executor never runs a step twice, so a run makes at most one coupon per step.
 */
class GenerateCoupon extends Action {

    public const ID = 'generate_coupon';

    public const PERCENT       = 'percent';
    public const FIXED_CART    = 'fixed_cart';
    public const FREE_SHIPPING = 'free_shipping';

    /** No 0/O or 1/I, so a code read out of an email can't be mistyped. */
    private const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    /** Tries before giving up on a unique code. */
    private const CODE_TRIES = 5;

    private const PREFIX_MAX = 20;

    private const DEFAULT_PREFIX = 'YM';

    /** The code previews and test emails show; never a real coupon. */
    public const SAMPLE_CODE = 'YMSAMPLE';

    /** @var CouponWriter */
    private $coupons;

    /** @var callable fn(): int Unix time. */
    private $now;

    /** @var \DateTimeZone|null Null = the store's. */
    private $timezone;

    /**
     * @param CouponWriter|null  $coupons  Coupon storage; null = WooCommerce.
     * @param callable|null      $now      Clock; null = `time()`.
     * @param \DateTimeZone|null $timezone Store timezone; null = `wp_timezone()`.
     */
    public function __construct( ?CouponWriter $coupons = null, ?callable $now = null, ?\DateTimeZone $timezone = null ) {
        $this->coupons  = $coupons ?? new WcCouponWriter();
        $this->now      = $now ?? 'time';
        $this->timezone = $timezone;
    }

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Generate coupon', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Create a single-use WooCommerce coupon to send in a later email.', 'yaymatik' );
    }

    public function icon(): string {
        return 'ticket';
    }

    public function category(): string {
        return self::CATEGORY_COUPON;
    }

    public function requires(): array {
        return [];
    }

    /**
     * The customer's email, when the coupon is limited to it.
     *
     * @param array $config Sanitized config.
     */
    public function uses( array $config ): array {
        return ! empty( $config['limit_to_customer'] ) ? [ CustomerData::ID ] : [];
    }

    public function fields(): array {
        return [
            Field::select( 'discount_type', __( 'Discount type', 'yaymatik' ), self::types() )->default( self::PERCENT ),
            Field::number( 'amount', __( 'Amount', 'yaymatik' ) )
                ->default( 10 )
                ->min( 0.01 )
                ->help( __( 'A percentage from 1 to 100, or an amount in your store currency.', 'yaymatik' ) )
                ->show_if( 'discount_type', [ self::PERCENT, self::FIXED_CART ] ),
            Field::number( 'expiry_days', __( 'Expires after (days)', 'yaymatik' ) )
                ->default( 30 )
                ->min( 1 )
                ->help( __( 'The coupon ends at the end of that day, in your store’s timezone. Leave blank for no expiry.', 'yaymatik' ) ),
            Field::number( 'minimum_spend', __( 'Minimum spend', 'yaymatik' ) )
                ->min( 0 )
                ->help( __( 'Leave blank for none.', 'yaymatik' ) ),
            Field::toggle( 'individual_use', __( 'Can’t be combined with other coupons', 'yaymatik' ) ),
            Field::toggle( 'limit_to_customer', __( 'Only the customer can use it', 'yaymatik' ) )
                ->default( true )
                ->help( __( 'Limited to the customer’s billing email.', 'yaymatik' ) ),
            Field::text( 'code_prefix', __( 'Code prefix', 'yaymatik' ) )
                ->default( self::DEFAULT_PREFIX )
                ->help( __( 'Letters, numbers, - and _. The code is the prefix and 8 random characters.', 'yaymatik' ) ),
        ];
    }

    public function summary(): string {
        // `%key%` placeholders the editor fills, not sprintf's `%d` / `%e`: phpcbf would number them.
        /* translators: %discount_type%: the discount, e.g. "10% off"; %expiry_days%: e.g. "expires in 30 days" or "never expires"; keep the placeholders as they are. */
        return __( '%discount_type%, %expiry_days%', 'yaymatik' ); // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText
    }

    public function outputs(): array {
        return [
            'code'       => [
                'label' => __( 'Coupon code', 'yaymatik' ),
                'type'  => Type::string(),
            ],
            'amount'     => [
                'label' => __( 'Coupon amount', 'yaymatik' ),
                'type'  => Type::string(),
            ],
            'expires_at' => [
                'label'   => __( 'Coupon expiry date', 'yaymatik' ),
                'type'    => Type::date(),
                'display' => fn( array $outputs, bool $html ) => self::text( self::expiry_text( (string) ( $outputs['expires_at'] ?? '' ) ), $html ),
            ],
            'box'        => [
                'label'   => __( 'Coupon box', 'yaymatik' ),
                'type'    => Type::string(),
                'block'   => true,
                'display' => [ self::class, 'box' ],
            ],
        ];
    }

    /**
     * Numbers kept as typed; the prefix trimmed and uppercased.
     *
     * @param mixed $config Raw config from the request.
     */
    public function sanitize( $config ): array {
        $raw    = is_array( $config ) ? $config : [];
        $config = Field::sanitize_all( $this->fields(), $raw );
        // Blank (null from the editor, '' from older configs) means none: no amount yet, no expiry, no
        // minimum. Only a missing value takes the default.
        foreach ( [ 'amount', 'expiry_days', 'minimum_spend' ] as $key ) {
            $value = array_key_exists( $key, $raw ) ? $raw[ $key ] : 0;
            if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
                $config[ $key ] = null;
            }
        }
        // A config saved without the key gets the default; an empty prefix stays empty.
        $config['code_prefix'] = strtoupper( array_key_exists( 'code_prefix', $raw ) ? $config['code_prefix'] : self::DEFAULT_PREFIX );
        return $config;
    }

    /**
     * @param array $config Output of `sanitize()`.
     * @return true|\WP_Error
     */
    public function validate( array $config ) {
        $error = $this->field_error( $config );
        if ( $error ) {
            return $error;
        }
        $amount = $config['amount'];
        if ( self::PERCENT === $config['discount_type'] && ( null === $amount || $amount < 1 || $amount > 100 ) ) {
            return $this->invalid( __( 'Enter a percentage from 1 to 100.', 'yaymatik' ), 'amount' );
        }
        if ( self::FIXED_CART === $config['discount_type'] && ( null === $amount || $amount <= 0 ) ) {
            return $this->invalid( __( 'Enter an amount above 0.', 'yaymatik' ), 'amount' );
        }
        $days = $config['expiry_days'];
        if ( null !== $days && ( $days < 1 || floor( $days ) != $days ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- 3.0 is a whole number.
            return $this->invalid( __( 'Enter a whole number of days, 1 or more, or leave it blank for no expiry.', 'yaymatik' ), 'expiry_days' );
        }
        $prefix = $config['code_prefix'];
        if ( ! preg_match( '/^[A-Z0-9_-]*$/', $prefix ) || strlen( $prefix ) > self::PREFIX_MAX ) {
            /* translators: %d: maximum length */
            return $this->invalid( sprintf( __( 'Use up to %d letters, numbers, - and _.', 'yaymatik' ), self::PREFIX_MAX ), 'code_prefix' );
        }
        return true;
    }

    /**
     * @param Scope $scope  Run scope.
     * @param array $config Sanitized, valid config.
     */
    public function run( Scope $scope, array $config ): StepResult {
        $email = '';
        if ( $config['limit_to_customer'] ) {
            $email = strtolower( trim( (string) $scope->get( CustomerData::ID . '.email' ) ) );
            if ( '' === $email ) {
                return StepResult::failed( new \WP_Error( 'yaymatik_coupon_no_email', __( 'The customer has no email address to limit the coupon to.', 'yaymatik' ) ) );
            }
        }

        $code = $this->unique_code( $config['code_prefix'] );
        if ( null === $code ) {
            return StepResult::failed( new \WP_Error( 'yaymatik_coupon_code', __( 'Couldn’t make a unique coupon code. Try a longer or different prefix.', 'yaymatik' ) ) );
        }

        $type    = $config['discount_type'];
        $free    = self::FREE_SHIPPING === $type;
        $amount  = $free ? 0.0 : (float) $config['amount'];
        $expires = null === $config['expiry_days'] ? null : $this->expiry( (int) $config['expiry_days'] );
        $id      = $this->coupons->create(
            [
                'code'           => $code,
                'discount_type'  => $free ? self::FIXED_CART : $type,
                'amount'         => $amount,
                'free_shipping'  => $free,
                'expires'        => $expires ? $expires->getTimestamp() : null,
                'minimum_amount' => null === $config['minimum_spend'] ? null : (float) $config['minimum_spend'],
                'individual_use' => (bool) $config['individual_use'],
                'email'          => $email,
                'run_id'         => (int) $scope->get( 'run.id' ),
                'node_id'        => (string) $scope->get( 'run.step' ),
                'description'    => '' === $email
                    ? __( 'Made by a YayMatik workflow.', 'yaymatik' )
                    /* translators: %s: customer email */
                    : sprintf( __( 'Made by a YayMatik workflow for %s.', 'yaymatik' ), $email ),
            ]
        );
        if ( ! $id ) {
            return StepResult::failed( new \WP_Error( 'yaymatik_coupon_save', __( 'Couldn’t save the coupon.', 'yaymatik' ) ) );
        }

        return StepResult::ok(
            [
                'code'       => $code,
                'amount'     => self::amount_text( $type, $amount ),
                'expires_at' => $expires ? $expires->format( 'Y-m-d' ) : '',
            ]
        );
    }

    /**
     * What a preview shows for this step's coupon: the sample code, the configured discount and expiry.
     * No coupon is made.
     *
     * @param array $config Sanitized config.
     */
    public function sample_outputs( array $config ): array {
        $type    = (string) $config['discount_type'];
        $expires = null === $config['expiry_days'] ? null : $this->expiry( max( 1, (int) $config['expiry_days'] ) );
        return [
            'code'       => self::SAMPLE_CODE,
            'amount'     => self::amount_text( $type, self::FREE_SHIPPING === $type ? 0.0 : (float) $config['amount'] ),
            'expires_at' => $expires ? $expires->format( 'Y-m-d' ) : '',
        ];
    }

    /**
     * Ready-made coupon block for emails: the discount, the code and the expiry. Empty when there's
     * no coupon (the step didn't run or was skipped), so the email leaves it out. Escapes its own values.
     *
     * @param array $outputs The step's outputs.
     * @param bool  $html    HTML instead of plain text.
     */
    public static function box( array $outputs, bool $html ): string {
        $code = (string) ( $outputs['code'] ?? '' );
        if ( '' === $code ) {
            return '';
        }
        $amount = (string) ( $outputs['amount'] ?? '' );
        $value  = self::free_shipping_text() === $amount
            ? $amount
            /* translators: %s: discount, e.g. "10%" or "$5.00" */
            : sprintf( __( '%s off', 'yaymatik' ), $amount );
        $date = self::expiry_text( (string) ( $outputs['expires_at'] ?? '' ) );
        /* translators: %s: date */
        $expiry = '' === $date ? '' : sprintf( __( 'Expires %s', 'yaymatik' ), $date );

        if ( ! $html ) {
            return implode( "\n", array_filter( [ $value, $code, $expiry ] ) );
        }

        $color = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color', '' ) );
        $color = $color ? $color : '#7f54b3';
        $box   = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 16px;border:2px dashed ' . esc_attr( $color ) . ';">'
            . '<tr><td style="padding:20px;text-align:center;">'
            . '<p style="margin:0 0 6px;font-size:15px;">' . esc_html( $value ) . '</p>'
            . '<p style="margin:0;font-size:24px;font-weight:bold;letter-spacing:2px;">' . esc_html( $code ) . '</p>';
        if ( '' !== $expiry ) {
            $box .= '<p style="margin:6px 0 0;font-size:13px;">' . esc_html( $expiry ) . '</p>';
        }
        return $box . '</td></tr></table>';
    }

    /**
     * The code: the prefix and random characters, not used by any coupon yet. Null after a few tries.
     *
     * @param string $prefix Sanitized prefix.
     */
    private function unique_code( string $prefix ): ?string {
        $last = strlen( self::CODE_CHARS ) - 1;
        for ( $try = 0; $try < self::CODE_TRIES; $try++ ) {
            $code = $prefix;
            for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
                $code .= self::CODE_CHARS[ random_int( 0, $last ) ];
            }
            if ( ! $this->coupons->exists( $code ) ) {
                return $code;
            }
        }
        return null;
    }

    /**
     * End of the day `$days` from today, in the store's timezone.
     *
     * @param int $days Whole days, 1 or more.
     */
    private function expiry( int $days ): \DateTimeImmutable {
        $timezone = $this->timezone ?? wp_timezone();
        return ( new \DateTimeImmutable( '@' . ( $this->now )() ) )
            ->setTimezone( $timezone )
            ->modify( "+{$days} days" )
            ->setTime( 23, 59, 59 );
    }

    /**
     * "10%", "$5.00" or "Free shipping", as plain text.
     *
     * @param string $type   Discount type.
     * @param float  $amount Amount.
     */
    private static function amount_text( string $type, float $amount ): string {
        if ( self::FREE_SHIPPING === $type ) {
            return self::free_shipping_text();
        }
        if ( self::PERCENT === $type ) {
            return ( floor( $amount ) == $amount ? (string) (int) $amount : (string) $amount ) . '%'; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- 10.0 prints as 10.
        }
        return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
    }

    private static function free_shipping_text(): string {
        return __( 'Free shipping', 'yaymatik' );
    }

    /**
     * A `Y-m-d` expiry in the site's date format; empty for no expiry.
     *
     * @param string $date `Y-m-d` or ''.
     */
    private static function expiry_text( string $date ): string {
        $time = '' === $date ? false : strtotime( $date . ' 12:00:00 UTC' );
        return false === $time ? '' : date_i18n( (string) get_option( 'date_format', 'F j, Y' ), $time );
    }

    /**
     * @param string $text Plain text.
     * @param bool   $html Escape for HTML.
     */
    private static function text( string $text, bool $html ): string {
        return $html ? esc_html( $text ) : $text;
    }

    private static function types(): array {
        return [
            self::PERCENT       => __( 'Percentage discount', 'yaymatik' ),
            self::FIXED_CART    => __( 'Fixed amount off the cart', 'yaymatik' ),
            self::FREE_SHIPPING => __( 'Free shipping', 'yaymatik' ),
        ];
    }
}
