<?php
namespace YayMatik\Tests\Unit\Steps;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Actions\AddOrderNote;
use YayMatik\Workflow\Actions\ChangeOrderStatus;
use YayMatik\Workflow\Actions\SendInternalEmail;
use YayMatik\Workflow\Actions\UpdateOrderMeta;
use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Steps\Step;
use YayMatik\Workflow\Triggers\OrderCreated;
use YayMatik\Workflow\Triggers\OrderStatusChanged;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/**
 * Characterization of the 7 steps' configs: what each one stores and what it accepts or rejects,
 * with the exact message. Written against v0.1 steps; the step contract must keep it passing.
 * Unknown merge tags pass here: the Validator reports them, with the same message
 * (see ValidatorTest).
 */
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
        ];
        return new $classes[ $id ]();
    }

    /**
     * @param string $id     Criteria field key.
     * @param string $op     Operator.
     * @param string $value  Value.
     * @param string $type   valueType.
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
     * @param array  $rules Rules.
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
            'created: empty'               => [ 'order_created', null, [], self::OK ],
            'created: junk dropped'        => [ 'order_created', [ 'x' => 1 ], [], self::OK ],

            // Order status changed.
            'status changed: empty'        => [
                'order_status_changed',
                [],
                [
                    'from'          => [],
                    'to'            => [],
                    'only_checkout' => false,
                ],
                self::OK,
            ],
            'status changed: slugs'        => [
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
            'status changed: unknown to'   => [ 'order_status_changed', [ 'to' => [ 'gone' ] ], null, 'Choose options from the list.' ],
            'status changed: unknown from' => [ 'order_status_changed', [ 'from' => [ 'completed', 'gone' ] ], null, 'Choose options from the list.' ],

            // Condition.
            'condition: empty'             => [ 'condition', 'junk', self::criteria( [] ), 'Add a rule, or remove this condition.' ],
            'condition: blank rule'        => [ 'condition', self::criteria( [ self::rule( '', '' ) ] ), null, 'Add a rule, or remove this condition.' ],
            'condition: match fallback'    => [
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
            'condition: unknown field'     => [ 'condition', self::criteria( [ self::rule( 'order.foo', 'equal', 'x' ) ] ), null, 'Choose data for each rule.' ],
            'condition: not conditional'   => [ 'condition', self::criteria( [ self::rule( 'order.customer', 'equal', 'x' ) ] ), null, 'Choose data for each rule.' ],
            'condition: bad operator'      => [ 'condition', self::criteria( [ self::rule( 'order.total', 'contains', '5' ) ] ), null, 'Choose a comparison for each rule.' ],
            'condition: number blank'      => [ 'condition', self::criteria( [ self::rule( 'order.total', 'greater_or_equal' ) ] ), null, 'Enter a value for Order total.' ],
            'condition: text blank'        => [ 'condition', self::criteria( [ self::rule( 'order.shipping_method', 'equal' ) ] ), null, 'Enter a value for Shipping method. Or choose “is empty” to check for a blank value.' ],
            'condition: not a number'      => [ 'condition', self::criteria( [ self::rule( 'order.total', 'greater_than', 'abc' ) ] ), null, 'Enter a number.' ],
            'condition: not a date'        => [ 'condition', self::criteria( [ self::rule( 'order.created_date', 'before', '2026/01/01' ) ] ), null, 'Enter a date (YYYY-MM-DD).' ],
            'condition: unknown option'    => [ 'condition', self::criteria( [ self::rule( 'order.status', 'is', 'gone' ) ] ), null, 'Choose a value from the list.' ],
            'condition: bad variable'      => [ 'condition', self::criteria( [ self::rule( 'order.total', 'equal', 'order.status', 'variable' ) ] ), null, 'Choose data to compare with.' ],
            'condition: later rule bad'    => [ 'condition', self::criteria( [ self::rule( 'order.status', 'is', 'processing' ), self::rule( 'order.total', 'less_than', 'x' ) ], 'any' ), null, 'Enter a number.' ],
            'condition: valid'             => [
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
            'condition: no-value op'       => [ 'condition', self::criteria( [ self::rule( 'order.total', 'equal', 'order.subtotal', 'variable' ), self::rule( 'customer.email', 'is_not_empty', 'order.foo', 'variable' ) ] ), null, self::OK ],

            // Send internal email.
            'email: empty'                 => [
                'send_internal_email',
                null,
                [
                    'recipients' => '',
                    'subject'    => '',
                    'message'    => '',
                ],
                self::SUBJECT,
            ],
            'email: bad recipient'         => [ 'send_internal_email', [ 'recipients' => 'a@x.com, bad' ], null, 'bad isn’t a valid email address.' ],
            'email: tag recipient'         => [
                'send_internal_email',
                [
                    'recipients' => '{{ customer.email }}',
                    'subject'    => 's',
                    'message'    => 'm',
                ],
                null,
                '{{ customer.email }} isn’t a valid email address.',
            ],
            'email: no message'            => [ 'send_internal_email', [ 'subject' => 's' ], null, 'Send internal email needs a message.' ],
            'email: unknown tag subject'   => [
                'send_internal_email',
                [
                    'subject' => 'Hi {{ order.foo }}',
                    'message' => 'm',
                ],
                null,
                self::OK,
            ],
            'email: unknown tag message'   => [
                'send_internal_email',
                [
                    'subject' => 's',
                    'message' => 'm {{ customer.bar }}',
                ],
                null,
                self::OK,
            ],
            'email: valid'                 => [
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
            'email: blank recipients ok'   => [
                'send_internal_email',
                [
                    'subject' => 's',
                    'message' => 'm',
                ],
                null,
                self::OK,
            ],

            // Change order status.
            'status: empty'                => [ 'change_order_status', [], [ 'status' => '' ], 'Choose the new status.' ],
            'status: unknown'              => [ 'change_order_status', [ 'status' => 'gone' ], [ 'status' => 'gone' ], 'Choose an option from the list.' ],
            'status: wc- prefix'           => [ 'change_order_status', [ 'status' => 'wc-completed' ], [ 'status' => 'completed' ], self::OK ],

            // Add order note.
            'note: empty'                  => [
                'add_order_note',
                null,
                [
                    'note' => '',
                    'type' => 'private',
                ],
                'Add order note needs a note.',
            ],
            'note: type fallback'          => [
                'add_order_note',
                [
                    'note' => " Hi\r\n<{{ order.number }}> ",
                    'type' => 'public',
                ],
                [
                    'note' => "Hi\n<{{ order.number }}>",
                    'type' => 'private',
                ],
                self::OK,
            ],
            'note: customer'               => [
                'add_order_note',
                [
                    'note' => 'Thanks',
                    'type' => 'customer',
                ],
                [
                    'note' => 'Thanks',
                    'type' => 'customer',
                ],
                self::OK,
            ],
            'note: unknown tag'            => [ 'add_order_note', [ 'note' => '{{ order.foo }}' ], null, self::OK ],

            // Update order meta.
            'meta: empty'                  => [
                'update_order_meta',
                null,
                [
                    'key'   => '',
                    'value' => '',
                ],
                'Update order custom field needs a field name.',
            ],
            'meta: clean key'              => [
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
            'meta: empty value ok'         => [ 'update_order_meta', [ 'key' => '_vip' ], null, self::OK ],
            'meta: unknown tag'            => [
                'update_order_meta',
                [
                    'key'   => '_vip',
                    'value' => '{{ order.foo }}',
                ],
                null,
                self::OK,
            ],
        ];
    }

    /**
     * @dataProvider configs
     *
     * @param string      $id       Step id.
     * @param mixed       $raw      Raw config.
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
