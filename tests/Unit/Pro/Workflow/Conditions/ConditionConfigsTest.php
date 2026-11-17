<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Conditions;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\Workflow\Conditions\CriteriaPro;

require_once dirname( __DIR__, 4 ) . '/Support/woocommerce-stubs.php';

/** Condition rules that compare with another field: raw config → stored config → first error. */
final class ConditionConfigsTest extends TestCase {

    /**
     * @param string $id
     * @param string $op
     * @param string $value
     * @param string $type
     */
    private static function rule( string $id, string $op, string $value = '', string $type = 'value' ): array {
        return [
            'field'     => $id,
            'operator'  => $op,
            'value'     => $value,
            'valueType' => $type,
        ];
    }

    /** @return array */
    public function configs(): array {
        return [
            'bad variable'    => [ [ self::rule( 'order.total', 'equal', 'order.status', 'variable' ) ], 'Choose data to compare with.' ],
            'valid variable'  => [ [ self::rule( 'order.status', 'is', 'processing' ), self::rule( 'order.total', 'greater_than', 'order.subtotal', 'variable' ) ], true ],
            'no-value op'     => [ [ self::rule( 'order.total', 'equal', 'order.subtotal', 'variable' ), self::rule( 'customer.email', 'is_not_empty', 'order.foo', 'variable' ) ], true ],
            'later rule bad'  => [ [ self::rule( 'order.total', 'less_than', 'order.subtotal', 'variable' ), self::rule( 'order.total', 'less_than', 'x' ) ], 'Enter a number.' ],
        ];
    }

    /**
     * @dataProvider configs
     *
     * @param array       $rules
     * @param true|string $expected
     */
    public function test_config( array $rules, $expected ): void {
        $step   = new CriteriaPro();
        $config = $step->sanitize(
            [
                'match' => 'all',
                'rules' => $rules,
            ]
        );
        $this->assertSame( array_column( $rules, 'valueType' ), array_column( $config['rules'], 'valueType' ), 'kept' );

        $result = $step->validate( $config );
        $this->assertSame( $expected, true === $result ? true : $result->get_error_message() );
    }
}
