<?php
namespace YayMatik\Tests\Unit\Core\Steps;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Actions\AddOrderNote;
use YayMatik\Workflow\Actions\ChangeOrderStatus;
use YayMatik\Workflow\Actions\SendInternalEmail;
use YayMatik\Workflow\Actions\UpdateCustomerMeta;
use YayMatik\Workflow\Actions\UpdateOrderMeta;
use YayMatik\Workflow\Actions\Wait;
use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Triggers\OrderCreated;
use YayMatik\Workflow\Triggers\OrderStatusChanged;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';

/** The steps' configs: what each one stores and what it accepts or rejects, with the exact message. */
final class StepConfigsTest extends TestCase {

    private const OK = true;

    private const SUBJECT = 'Send internal email needs a subject.';


    private static function step( string $id ): Step {
        $classes = [
            OrderCreated::ID       => OrderCreated::class,
            OrderStatusChanged::ID => OrderStatusChanged::class,
            Criteria::ID           => Criteria::class,
            SendInternalEmail::ID  => SendInternalEmail::class,
            ChangeOrderStatus::ID  => ChangeOrderStatus::class,
            AddOrderNote::ID       => AddOrderNote::class,
            UpdateOrderMeta::ID    => UpdateOrderMeta::class,
            UpdateCustomerMeta::ID => UpdateCustomerMeta::class,
            Wait::ID               => Wait::class,
        ];
        return new $classes[ $id ]();
    }

    /**
     * @param string $id    Criteria field key.
     * @param string $op
     * @param string $value
     * @param string $type  valueType.
     */
    private static function rule( string $id, string $op, string $value = '', string $type = 'value' ): array {
        return [
            'field'     => $id,
            'operator'  => $op,
            'value'     => $value,
            'valueType' => $type,
        ];
    }

    /**
     * @param array  $rules
     * @param string $join  all|any.
     */
    private static function criteria( array $rules, string $join = 'all' ): array {
        return [
            'match' => $join,
            'rules' => $rules,
        ];
    }

    /** [ step id, raw config, stored config (null = not pinned), true or the exact message ]. */
    public function configs(): array {
        return [
            // Order created: nothing to configure.
            'created: empty'                => [ 'order_created', null, [], self::OK ],
            'created: junk dropped'         => [ 'order_created', [ 'x' => 1 ], [], self::OK ],

            // Order status changed.
            'status changed: empty'         => [
                'order_status_changed',
                [],
                [
                    'from'          => [],
                    'to'            => [],
                    'only_checkout' => false,
                ],
                self::OK,
            ],
            'status changed: slugs'         => [
                'order_status_changed',
                [
                    'from'          => [ 'wc-pending', 'pending', 'Processing', '', 3, [ 'x' ] ],
                    'to'            => 'completed',
                    'only_checkout' => '1',
                ],
                [
                    'from'          => [ 'pending', 'processing' ],
                    'to'            => [],
                    'only_checkout' => true,
                ],
                self::OK,
            ],
            'status changed: unknown to'    => [ 'order_status_changed', [ 'to' => [ 'gone' ] ], null, 'Choose options from the list.' ],
            'status changed: unknown from'  => [ 'order_status_changed', [ 'from' => [ 'completed', 'gone' ] ], null, 'Choose options from the list.' ],

            // Condition.
            'condition: empty'              => [ 'condition', 'junk', self::criteria( [] ), 'Add a rule, or remove this condition.' ],
            'condition: blank rule'         => [ 'condition', self::criteria( [ self::rule( '', '' ) ] ), null, 'Add a rule, or remove this condition.' ],
            'condition: match fallback'     => [
                'condition',
                [
                    'match' => 'xx',
                    'rules' => [
                        [
                            'field'    => 'Order.total!',
                            'operator' => 'Greater_Than',
                            'value'    => ' 5 ',
                        ],
                        'junk',
                    ],
                ],
                self::criteria( [ self::rule( 'rder.total', 'greater_than', '5' ), self::rule( '', '' ) ] ),
                'Choose data for each rule.',
            ],
            'condition: unknown field'      => [ 'condition', self::criteria( [ self::rule( 'order.foo', 'equal', 'x' ) ] ), null, 'Choose data for each rule.' ],
            'condition: not conditional'    => [ 'condition', self::criteria( [ self::rule( 'order.customer', 'equal', 'x' ) ] ), null, 'Choose data for each rule.' ],
            'condition: bad operator'       => [ 'condition', self::criteria( [ self::rule( 'order.total', 'contains', '5' ) ] ), null, 'Choose a comparison for each rule.' ],
            'condition: number blank'       => [ 'condition', self::criteria( [ self::rule( 'order.total', 'greater_or_equal' ) ] ), null, 'Enter a value for Order total.' ],
            'condition: text blank'         => [ 'condition', self::criteria( [ self::rule( 'order.shipping_method', 'equal' ) ] ), null, 'Enter a value for Shipping method. Or choose “is empty” to check for a blank value.' ],
            'condition: not a number'       => [ 'condition', self::criteria( [ self::rule( 'order.total', 'greater_than', 'abc' ) ] ), null, 'Enter a number.' ],
            'condition: not a date'         => [ 'condition', self::criteria( [ self::rule( 'order.created_date', 'before', '2026/01/01' ) ] ), null, 'Enter a date (YYYY-MM-DD).' ],
            'condition: unknown option'     => [ 'condition', self::criteria( [ self::rule( 'order.status', 'is', 'gone' ) ] ), null, 'Choose a value from the list.' ],
            'condition: bad variable'       => [ 'condition', self::criteria( [ self::rule( 'order.total', 'equal', 'order.status', 'variable' ) ] ), null, 'Choose data to compare with.' ],
            'condition: later rule bad'     => [ 'condition', self::criteria( [ self::rule( 'order.status', 'is', 'processing' ), self::rule( 'order.total', 'less_than', 'x' ) ], 'any' ), null, 'Enter a number.' ],
            'condition: valid'              => [
                'condition',
                self::criteria(
                    [
                        self::rule( 'order.status', 'is', 'processing' ),
                        self::rule( 'order.total', 'greater_than', 'order.subtotal', 'variable' ),
                        self::rule( 'order.shipping_method', 'is_empty' ),
                        self::rule( 'order.created_date', 'after', '2026-01-01' ),
                        self::rule( 'customer.email', 'ends_with', '<b>@x.com</b>' ),
                    ],
                    'any'
                ),
                self::criteria(
                    [
                        self::rule( 'order.status', 'is', 'processing' ),
                        self::rule( 'order.total', 'greater_than', 'order.subtotal', 'variable' ),
                        self::rule( 'order.shipping_method', 'is_empty' ),
                        self::rule( 'order.created_date', 'after', '2026-01-01' ),
                        self::rule( 'customer.email', 'ends_with', '@x.com' ),
                    ],
                    'any'
                ),
                self::OK,
            ],
            'condition: no-value op'        => [ 'condition', self::criteria( [ self::rule( 'order.total', 'equal', 'order.subtotal', 'variable' ), self::rule( 'customer.email', 'is_not_empty', 'order.foo', 'variable' ) ] ), null, self::OK ],

            // Send internal email.
            'email: empty'                  => [
                'send_internal_email',
                null,
                [
                    'recipients' => '',
                    'subject'    => '',
                    'message'    => '',
                ],
                self::SUBJECT,
            ],
            'email: bad recipient'          => [ 'send_internal_email', [ 'recipients' => 'a@x.com, bad' ], null, 'bad isn’t a valid email address.' ],
            'email: tag recipient'          => [
                'send_internal_email',
                [
                    'recipients' => '{{ customer.email }}',
                    'subject'    => 's',
                    'message'    => 'm',
                ],
                null,
                '{{ customer.email }} isn’t a valid email address.',
            ],
            'email: no message'             => [ 'send_internal_email', [ 'subject' => 's' ], null, 'Send internal email needs a message.' ],
            // Unknown merge tags pass here: the Validator reports them (see ValidatorTest).
            'email: unknown tag subject'    => [
                'send_internal_email',
                [
                    'subject' => 'Hi {{ order.foo }}',
                    'message' => 'm',
                ],
                null,
                self::OK,
            ],
            'email: unknown tag message'    => [
                'send_internal_email',
                [
                    'subject' => 's',
                    'message' => 'm {{ customer.bar }}',
                ],
                null,
                self::OK,
            ],
            'email: valid'                  => [
                'send_internal_email',
                [
                    'recipients' => " a@x.com,\nb@x.com ",
                    'subject'    => "Order {{ order.number }}\r\nBcc: x",
                    'message'    => "Hi <{{ customer.email }}>\r\nbye\x07",
                ],
                [
                    'recipients' => 'a@x.com, b@x.com',
                    'subject'    => 'Order {{ order.number }} Bcc: x',
                    'message'    => "Hi <{{ customer.email }}>\nbye",
                ],
                self::OK,
            ],
            'email: blank recipients ok'    => [
                'send_internal_email',
                [
                    'subject' => 's',
                    'message' => 'm',
                ],
                null,
                self::OK,
            ],

            // Change order status.
            'status: empty'                 => [ 'change_order_status', [], [ 'status' => '' ], 'Choose the new status.' ],
            'status: unknown'               => [ 'change_order_status', [ 'status' => 'gone' ], [ 'status' => 'gone' ], 'Choose an option from the list.' ],
            'status: wc- prefix'            => [ 'change_order_status', [ 'status' => 'wc-completed' ], [ 'status' => 'completed' ], self::OK ],

            // Add order note.
            'note: empty'                   => [
                'add_order_note',
                null,
                [
                    'type' => 'private',
                    'note' => '',
                ],
                'Add order note needs a note.',
            ],
            'note: type fallback'           => [
                'add_order_note',
                [
                    'note' => " Hi\r\n<{{ order.number }}> ",
                    'type' => 'public',
                ],
                [
                    'type' => 'private',
                    'note' => "Hi\n<{{ order.number }}>",
                ],
                self::OK,
            ],
            'note: customer'                => [
                'add_order_note',
                [
                    'note' => 'Thanks',
                    'type' => 'customer',
                ],
                [
                    'type' => 'customer',
                    'note' => 'Thanks',
                ],
                self::OK,
            ],
            'note: unknown tag'             => [ 'add_order_note', [ 'note' => '{{ order.foo }}' ], null, self::OK ],

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
            'wait: default'                 => [ 'wait', null, self::delay( 1, 'days' ), self::OK ],
            'wait: unknown unit'            => [ 'wait', self::delay( 3, 'years' ), self::delay( 3, 'days' ), self::OK ],
            'wait: blank value'             => [ 'wait', self::delay( '', 'hours' ), self::delay( 1, 'hours' ), self::OK ],
            'wait: a year of weeks'         => [ 'wait', self::delay( 52, 'weeks' ), null, self::OK ],
            'wait: zero'                    => [ 'wait', self::delay( 0, 'days' ), null, 'Enter a whole number, 1 or more.' ],
            'wait: fraction'                => [ 'wait', self::delay( 1.5, 'days' ), null, 'Enter a whole number, 1 or more.' ],
            'wait: over a year'             => [ 'wait', self::delay( 8761, 'hours' ), null, 'Wait at most a year.' ],
        ];
    }

    /**
     * @param mixed  $value
     * @param string $unit
     */
    private static function delay( $value, string $unit ): array {
        return [
            'delay' => [
                'value' => $value,
                'unit'  => $unit,
            ],
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
        $step   = self::step( $id );
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
