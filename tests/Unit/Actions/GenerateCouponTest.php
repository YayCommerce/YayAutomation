<?php
namespace YayMatik\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeCouponWriter;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Template;
use YayMatik\Workflow\Core\Runtime\Executor;
use YayMatik\Workflow\Actions\GenerateCoupon;
use YayMatik\Workflow\Core\Steps\StepResult;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** Generate coupon with the coupon storage faked: config, the coupon it asks for, outputs, the box. */
final class GenerateCouponTest extends TestCase {

    /** 2026-10-16 22:30 UTC. */
    private const NOW = 1792189800;

    /** @var FakeCouponWriter */
    private $coupons;

    protected function setUp(): void {
        $this->coupons = new FakeCouponWriter();
    }

    private function step( string $timezone = 'UTC' ): GenerateCoupon {
        return new GenerateCoupon( $this->coupons, fn() => self::NOW, new \DateTimeZone( $timezone ) );
    }

    /**
     * Sanitized config: the step's defaults with these values.
     *
     * @param array $values Raw values.
     */
    private function config( array $values = [] ): array {
        return $this->step()->sanitize( $values + $this->defaults() );
    }

    private function defaults(): array {
        $raw = [];
        foreach ( $this->step()->fields() as $field ) {
            $raw[ $field->key() ] = $field->to_array()['default'] ?? null;
        }
        return $raw;
    }

    private function run_step( array $config, string $timezone = 'UTC', string $email = 'Ann@X.com ' ): StepResult {
        $scope = new Scope( [ 'trigger' => [ 'customer' => [ 'email' => $email ] ] ] );
        return $this->step( $timezone )->run( $scope, $config );
    }

    /**
     * The validation error's field and message, or null when valid.
     *
     * @param array $values Raw values.
     */
    private function error( array $values ): ?array {
        $result = $this->step()->validate( $this->config( $values ) );
        return true === $result ? null : [ $result->get_error_data()['field'] ?? '', $result->get_error_message() ];
    }

    public function test_defaults_are_10_percent_30_days_ym_limited_to_the_customer(): void {
        $this->assertSame(
            [
                'discount_type'     => 'percent',
                'amount'            => 10,
                'expiry_days'       => 30,
                'minimum_spend'     => null,
                'individual_use'    => false,
                'limit_to_customer' => true,
                'code_prefix'       => 'YM',
            ],
            $this->config()
        );
        $this->assertNull( $this->error( [] ) );
    }

    public function test_blank_expiry_and_minimum_mean_none_and_the_prefix_is_uppercased(): void {
        $config = $this->config(
            [
                'expiry_days'   => ' ',
                'minimum_spend' => '',
                'code_prefix'   => ' thanks-',
            ]
        );

        $this->assertNull( $config['expiry_days'] );
        $this->assertNull( $config['minimum_spend'] );
        $this->assertSame( 'THANKS-', $config['code_prefix'] );
        $this->assertSame( 30, $this->step()->sanitize( [] )['expiry_days'], 'a missing value takes the default' );
    }

    public function test_null_from_a_cleared_number_field_means_none(): void {
        $config = $this->config(
            [
                'amount'        => null,
                'expiry_days'   => null,
                'minimum_spend' => null,
            ]
        );

        $this->assertNull( $config['amount'] );
        $this->assertNull( $config['expiry_days'] );
        $this->assertNull( $config['minimum_spend'] );
        $this->assertSame( 30, $this->config( [ 'expiry_days' => '30' ] )['expiry_days'], 'text numbers from older configs' );
    }

    public function test_a_missing_prefix_is_ym_and_an_empty_one_is_none(): void {
        $this->assertSame( 'YM', $this->step()->sanitize( [ 'discount_type' => 'percent' ] )['code_prefix'] );
        $this->assertSame( '', $this->step()->sanitize( [ 'code_prefix' => '' ] )['code_prefix'] );

        $this->run_step( $this->config( [ 'code_prefix' => '' ] ) );
        $this->assertMatchesRegularExpression( '/^[A-Z2-9]{8}$/', $this->coupons->created[0]['code'] );
    }

    public function test_the_coupon_records_the_run_and_step_that_made_it(): void {
        $scope = new Scope( [ 'trigger' => [ 'customer' => [ 'email' => 'ann@x.com' ] ] ] );
        $scope->set_current_step( 42, 'coupon' );
        $this->step()->run( $scope, $this->config() );

        $this->assertSame( [ 42, 'coupon' ], [ $this->coupons->created[0]['run_id'], $this->coupons->created[0]['node_id'] ] );

        $this->run_step( $this->config() );
        $this->assertSame( [ 0, '' ], [ $this->coupons->created[1]['run_id'], $this->coupons->created[1]['node_id'] ], 'outside a run' );
    }

    public function test_percent_is_1_to_100(): void {
        $message = [ 'amount', 'Enter a percentage from 1 to 100.' ];
        $this->assertSame( [ 'amount', 'Enter 0.01 or more.' ], $this->error( [ 'amount' => 0 ] ), 'the field’s min, checked in the editor too' );
        $this->assertSame( $message, $this->error( [ 'amount' => 0.5 ] ) );
        $this->assertSame( $message, $this->error( [ 'amount' => 100.5 ] ) );
        $this->assertSame( $message, $this->error( [ 'amount' => 150 ] ) );
        $this->assertSame( $message, $this->error( [ 'amount' => '' ] ) );
        $this->assertNull( $this->error( [ 'amount' => 1 ] ) );
        $this->assertNull( $this->error( [ 'amount' => '100' ] ) );
    }

    public function test_fixed_amount_is_above_0_and_free_shipping_needs_none(): void {
        foreach ( [ 0, -5 ] as $amount ) {
            $this->assertSame(
                [ 'amount', 'Enter 0.01 or more.' ],
                $this->error(
                    [
                        'discount_type' => 'fixed_cart',
                        'amount'        => $amount,
                    ]
                ),
                (string) $amount
            );
        }
        $this->assertSame(
            [ 'amount', 'Enter an amount above 0.' ],
            $this->error(
                [
                    'discount_type' => 'fixed_cart',
                    'amount'        => '',
                ]
            )
        );
        $this->assertNull(
            $this->error(
                [
                    'discount_type' => 'fixed_cart',
                    'amount'        => 0.5,
                ]
            )
        );
        $this->assertNull(
            $this->error(
                [
                    'discount_type' => 'fixed_cart',
                    'amount'        => 250,
                ]
            ),
            'no upper limit'
        );
        $this->assertNull(
            $this->error(
                [
                    'discount_type' => 'free_shipping',
                    'amount'        => '',
                ]
            )
        );
    }

    public function test_expiry_is_whole_days_from_1_or_blank(): void {
        $message = [ 'expiry_days', 'Enter a whole number of days, 1 or more, or leave it blank for no expiry.' ];
        $this->assertSame( [ 'expiry_days', 'Enter 1 or more.' ], $this->error( [ 'expiry_days' => 0 ] ), 'the field’s min, checked in the editor too' );
        $this->assertSame( $message, $this->error( [ 'expiry_days' => 1.5 ] ) );
        $this->assertNull( $this->error( [ 'expiry_days' => 1 ] ) );
        $this->assertNull( $this->error( [ 'expiry_days' => '' ] ) );
    }

    public function test_prefix_is_letters_numbers_dash_underscore(): void {
        $message = [ 'code_prefix', 'Use up to 20 letters, numbers, - and _.' ];
        $this->assertSame( $message, $this->error( [ 'code_prefix' => 'ym!' ] ) );
        $this->assertSame( $message, $this->error( [ 'code_prefix' => 'A B' ] ) );
        $this->assertSame( $message, $this->error( [ 'code_prefix' => str_repeat( 'A', 21 ) ] ) );
        $this->assertNull( $this->error( [ 'code_prefix' => 'thanks_2-' ] ) );
        $this->assertNull( $this->error( [ 'code_prefix' => '' ] ) );
    }

    public function test_a_percent_coupon_single_use_for_the_customer_until_the_end_of_the_day(): void {
        $result = $this->run_step( $this->config(), 'America/New_York' );

        $this->assertTrue( $result->is_ok() );
        $this->assertCount( 1, $this->coupons->created );
        $coupon = $this->coupons->created[0];
        $this->assertMatchesRegularExpression( '/^YM[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{8}$/', $coupon['code'] );
        $this->assertSame( 'percent', $coupon['discount_type'] );
        $this->assertSame( 10.0, $coupon['amount'] );
        $this->assertFalse( $coupon['free_shipping'] );
        $this->assertSame( 'ann@x.com', $coupon['email'], 'the billing email, lowercased' );
        $this->assertNull( $coupon['minimum_amount'] );
        $this->assertFalse( $coupon['individual_use'] );
        // 18:30 on Oct 16 in New York, + 30 days → the end of Nov 15 there.
        $this->assertSame( '2026-11-15 23:59:59 EST', ( new \DateTimeImmutable( '@' . $coupon['expires'] ) )->setTimezone( new \DateTimeZone( 'America/New_York' ) )->format( 'Y-m-d H:i:s T' ) );
        $this->assertSame(
            [
                'code'       => $coupon['code'],
                'amount'     => '10%',
                'expires_at' => '2026-11-15',
            ],
            $result->outputs()
        );
    }

    public function test_the_last_day_is_counted_in_the_store_timezone(): void {
        // Already Oct 17 in Ho Chi Minh City.
        $result = $this->run_step( $this->config( [ 'expiry_days' => 1 ] ), 'Asia/Ho_Chi_Minh' );

        $this->assertSame( '2026-10-18', $result->outputs()['expires_at'] );
    }

    public function test_free_shipping_and_fixed_amount(): void {
        $free = $this->run_step(
            $this->config(
                [
                    'discount_type' => 'free_shipping',
                    'amount'        => '',
                ]
            )
        );
        $this->assertSame( [ 'fixed_cart', 0.0, true ], array_values( array_intersect_key( $this->coupons->created[0], array_flip( [ 'discount_type', 'amount', 'free_shipping' ] ) ) ) );
        $this->assertSame( 'Free shipping', $free->outputs()['amount'] );

        $fixed = $this->run_step(
            $this->config(
                [
                    'discount_type'  => 'fixed_cart',
                    'amount'         => '5',
                    'minimum_spend'  => '50',
                    'individual_use' => true,
                ]
            )
        );
        $this->assertSame( [ 'fixed_cart', 5.0, false, 50.0, true ], array_values( array_intersect_key( $this->coupons->created[1], array_flip( [ 'discount_type', 'amount', 'free_shipping', 'minimum_amount', 'individual_use' ] ) ) ) );
        $this->assertSame( '$5.00', $fixed->outputs()['amount'] );
    }

    public function test_no_expiry(): void {
        $result = $this->run_step( $this->config( [ 'expiry_days' => '' ] ) );

        $this->assertNull( $this->coupons->created[0]['expires'] );
        $this->assertSame( '', $result->outputs()['expires_at'] );
    }

    public function test_not_limited_needs_no_customer(): void {
        $result = $this->run_step( $this->config( [ 'limit_to_customer' => false ] ), 'UTC', '' );

        $this->assertTrue( $result->is_ok() );
        $this->assertSame( '', $this->coupons->created[0]['email'] );
        $this->assertSame( [], $this->step()->uses( $this->config( [ 'limit_to_customer' => false ] ) ) );
        $this->assertSame( [ 'customer' ], $this->step()->uses( $this->config() ) );
    }

    public function test_limited_without_a_customer_email_fails(): void {
        $result = $this->run_step( $this->config(), 'UTC', '' );

        $this->assertTrue( $result->is_failed() );
        $this->assertSame( 'The customer has no email address to limit the coupon to.', $result->message() );
        $this->assertSame( [], $this->coupons->created );
    }

    public function test_a_taken_code_is_retried(): void {
        $this->coupons->taken = 2;
        $result               = $this->run_step( $this->config() );

        $this->assertTrue( $result->is_ok() );
        $this->assertCount( 3, $this->coupons->checked );
        $this->assertSame( $this->coupons->checked[2], $this->coupons->created[0]['code'] );
    }

    public function test_no_unique_code_after_5_tries_fails(): void {
        $this->coupons->taken = 99;
        $result               = $this->run_step( $this->config() );

        $this->assertTrue( $result->is_failed() );
        $this->assertSame( 'yaymatik_coupon_code', $result->code() );
        $this->assertSame( 'Couldn’t make a unique coupon code. Try a longer or different prefix.', $result->message() );
        $this->assertCount( 5, $this->coupons->checked );
        $this->assertSame( [], $this->coupons->created );
    }

    public function test_a_failed_save_fails(): void {
        $this->coupons->next_id = 0;

        $this->assertSame( 'Couldn’t save the coupon.', $this->run_step( $this->config() )->message() );
    }

    public function test_previews_get_the_sample_code_and_no_coupon(): void {
        $this->assertSame(
            [
                'code'       => 'YMSAMPLE',
                'amount'     => '15%',
                'expires_at' => '2026-10-23',
            ],
            $this->step()->sample_outputs(
                $this->config(
                    [
                        'amount'      => 15,
                        'expiry_days' => 7,
                    ]
                )
            )
        );
        $this->assertSame(
            'Free shipping',
            $this->step()->sample_outputs(
                $this->config(
                    [
                        'discount_type' => 'free_shipping',
                        'amount'        => '',
                    ]
                )
            )['amount']
        );
        $this->assertSame( '', $this->step()->sample_outputs( $this->config( [ 'expiry_days' => '' ] ) )['expires_at'] );
        $this->assertSame( [], $this->coupons->created );
        $this->assertSame( [], $this->coupons->checked );
    }

    public function test_the_box_escapes_its_values(): void {
        $outputs = [
            'code'       => 'YM<b>"X"',
            'amount'     => '10% & more',
            'expires_at' => '2026-11-15',
        ];
        $html    = GenerateCoupon::box( $outputs, true );

        $this->assertStringContainsString( '>YM&lt;b&gt;&quot;X&quot;</p>', $html );
        $this->assertStringContainsString( '>10% &amp; more off</p>', $html );
        $this->assertStringContainsString( '>Expires November 15, 2026</p>', $html );
        $this->assertStringStartsWith( '<table role="presentation"', $html );
        $this->assertStringNotContainsString( '<b>', $html );
        $this->assertSame( "10% & more off\nYM<b>\"X\"\nExpires November 15, 2026", GenerateCoupon::box( $outputs, false ) );
    }

    public function test_the_box_for_free_shipping_and_no_expiry(): void {
        $html = GenerateCoupon::box(
            [
                'code'       => 'YMAAAA2222',
                'amount'     => 'Free shipping',
                'expires_at' => '',
            ],
            true
        );

        $this->assertStringContainsString( '>Free shipping</p>', $html );
        $this->assertStringNotContainsString( 'Expires', $html );
    }

    public function test_no_coupon_no_box(): void {
        $this->assertSame( '', GenerateCoupon::box( [], true ) );
    }

    public function test_an_email_reads_the_box_and_the_expiry_through_the_scope(): void {
        $nodes = [
            'coupon' => [ 'data' => [ 'block' => 'generate_coupon' ] ],
        ];
        $scope = new Scope();
        $scope->set_output_display( Executor::output_display( $nodes, fn( string $id ) => 'generate_coupon' === $id ? $this->step() : null ) );
        $template = new Template();

        $this->assertSame( '<p></p>', $template->render_html( '<p>{{ steps.coupon.box }}</p>', $scope ), 'the step didn’t run: no box' );

        $scope->set_step_outputs(
            'coupon',
            [
                'code'       => 'YMAAAA2222',
                'amount'     => '10%',
                'expires_at' => '2026-11-15',
            ]
        );
        $html = $template->render_html( '<p>Use {{ steps.coupon.code }} by {{ steps.coupon.expires_at }}</p><p>{{ steps.coupon.box }}</p>', $scope );

        $this->assertStringStartsWith( '<p>Use YMAAAA2222 by November 15, 2026</p><table role="presentation"', $html, 'the box replaces its paragraph' );
        $this->assertSame( '2026-11-15', $scope->get( 'steps.coupon.expires_at' ), 'the typed value stays a date' );
    }
}
