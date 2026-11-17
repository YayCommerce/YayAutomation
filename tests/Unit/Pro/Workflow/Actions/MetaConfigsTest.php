<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Actions;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\Workflow\Actions\UpdateCustomerMeta;
use YayMatik\Pro\Workflow\Actions\UpdateOrderMeta;

require_once dirname( __DIR__, 4 ) . '/Support/woocommerce-stubs.php';

/** Update order / customer custom field: raw config → stored config → first error. */
final class MetaConfigsTest extends TestCase {

    private const OK = true;

    /** @return array */
    public function configs(): array {
        return [
            // Update order meta.
            'meta: empty'                   => [
                'update_order_meta',
                null,
                [
                    'key'   => '',
                    'value' => '',
                ],
                'Update order custom field needs a field name.',
            ],
            'meta: clean key'               => [
                'update_order_meta',
                [
                    'key'   => ' <b>_vip</b> ',
                    'value' => "a\nb",
                ],
                [
                    'key'   => '_vip',
                    'value' => 'a b',
                ],
                self::OK,
            ],
            'meta: empty value ok'          => [ 'update_order_meta', [ 'key' => '_vip' ], null, self::OK ],
            'meta: internal key'            => [ 'update_order_meta', [ 'key' => '_order_total' ], null, 'WooCommerce or YayMatik manages this field. Choose another field name.' ],
            'meta: internal key, upper'     => [ 'update_order_meta', [ 'key' => '_ORDER_TOTAL' ], null, 'WooCommerce or YayMatik manages this field. Choose another field name.' ],
            'meta: own prefix'              => [ 'update_order_meta', [ 'key' => '_yaymatik_x' ], null, 'WooCommerce or YayMatik manages this field. Choose another field name.' ],
            'meta: unknown tag'             => [
                'update_order_meta',
                [
                    'key'   => '_vip',
                    'value' => '{{ order.foo }}',
                ],
                null,
                self::OK,
            ],
            // Update customer meta.
            'customer meta: empty'          => [
                'update_customer_meta',
                null,
                [
                    'key'   => '',
                    'value' => '',
                ],
                'Update customer custom field needs a field name.',
            ],
            'customer meta: ok'             => [ 'update_customer_meta', [ 'key' => 'vip' ], null, self::OK ],
            'customer meta: capabilities'   => [ 'update_customer_meta', [ 'key' => 'wp_capabilities' ], null, 'This custom field is protected and can’t be changed.' ],
            'customer meta: a network site' => [ 'update_customer_meta', [ 'key' => 'WP_2_USER_LEVEL' ], null, 'This custom field is protected and can’t be changed.' ],
            'customer meta: sessions'       => [ 'update_customer_meta', [ 'key' => 'session_tokens' ], null, 'This custom field is protected and can’t be changed.' ],
            'customer meta: own prefix'     => [ 'update_customer_meta', [ 'key' => '_yaymatik_x' ], null, 'This custom field is protected and can’t be changed.' ],
        ];
    }

    /**
     * @dataProvider configs
     *
     * @param string      $id
     * @param mixed       $raw
     * @param array|null  $stored   Expected sanitized config, or null to skip.
     * @param true|string $expected True, or the exact error message.
     */
    public function test_config( string $id, $raw, ?array $stored, $expected ): void {
        $step   = UpdateOrderMeta::ID === $id ? new UpdateOrderMeta() : new UpdateCustomerMeta();
        $config = $step->sanitize( $raw );
        if ( null !== $stored ) {
            $this->assertSame( $stored, $config, 'stored config' );
        }

        $result = $step->validate( $config );
        if ( true === $expected ) {
            $this->assertTrue( $result );
            return;
        }
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'yaymatik_invalid_config', $result->get_error_code() );
        $this->assertSame( $expected, $result->get_error_message() );
    }
}
