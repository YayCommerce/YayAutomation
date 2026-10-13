<?php
namespace YayAutomation\Tests\Unit\Steps;

use PHPUnit\Framework\TestCase;
use YayAutomation\Workflow\Data\Type;
use YayAutomation\Workflow\Extend\Modules;
use YayAutomation\Workflow\Extend\Plugins;
use YayAutomation\Workflow\Steps\Actions\AddOrderNote;
use YayAutomation\Workflow\Steps\Actions\ChangeOrderStatus;
use YayAutomation\Workflow\Steps\Actions\SendInternalEmail;
use YayAutomation\Workflow\Steps\Actions\UpdateOrderMeta;
use YayAutomation\Workflow\Steps\Conditions\Criteria;
use YayAutomation\Workflow\Steps\Core\Field;
use YayAutomation\Workflow\Steps\Core\Step;
use YayAutomation\Workflow\Steps\Core\StepIcons;
use YayAutomation\Workflow\Steps\Triggers\OrderCreated;
use YayAutomation\Workflow\Steps\Triggers\OrderStatusChanged;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** The declarative step contract, for each of the 7 core steps. */
final class StepContractTest extends TestCase {

    /** @return Step[] Keyed by id. */
    private static function steps(): array {
        $steps = [];
        foreach ( [ new OrderCreated(), new OrderStatusChanged(), new Criteria(), new SendInternalEmail(), new ChangeOrderStatus(), new AddOrderNote(), new UpdateOrderMeta() ] as $step ) {
            $steps[ $step->id() ] = $step;
        }
        return $steps;
    }

    public function steps_provider(): array {
        return array_map( fn( Step $step ) => [ $step ], self::steps() );
    }

    /**
     * @dataProvider steps_provider
     *
     * @param Step $step Step.
     */
    public function test_declares_its_identity( Step $step ): void {
        $this->assertNotSame( '', $step->label() );
        $this->assertNotSame( '', $step->description() );
        $this->assertContains( $step->plugin(), [ Plugins::WOOCOMMERCE, Plugins::YAYAUTOMATION ] );
        $this->assertSame( Modules::CORE, $step->module() );
        $this->assertContains( $step->on_error(), [ Step::ON_ERROR_STOP, Step::ON_ERROR_CONTINUE ] );
    }

    /**
     * @dataProvider steps_provider
     *
     * @param Step $step Step.
     */
    public function test_icon_is_a_name_from_core_list( Step $step ): void {
        $this->assertTrue( StepIcons::exists( $step->icon() ), $step->icon() . ' is not in StepIcons::NAMES' );
        $this->assertMatchesRegularExpression( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $step->icon(), 'a name, never SVG or a URL' );
    }

    public function test_icon_list_holds_only_names(): void {
        $this->assertSame( array_values( array_unique( StepIcons::NAMES ) ), StepIcons::NAMES );
        foreach ( StepIcons::NAMES as $name ) {
            $this->assertMatchesRegularExpression( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $name );
        }
        $this->assertFalse( StepIcons::exists( '<svg/>' ) );
    }

    /**
     * @dataProvider steps_provider
     *
     * @param Step $step Step.
     */
    public function test_fields_have_unique_keys_and_summary_names_only_them( Step $step ): void {
        $keys = array_map( fn( Field $field ) => $field->key(), $step->fields() );
        $this->assertSame( array_unique( $keys ), $keys );

        preg_match_all( '/%([a-z_]+)%/', $step->summary(), $placeholders );
        $this->assertSame( [], array_diff( $placeholders[1], $keys ), 'summary placeholders are field keys' );
    }

    /**
     * @dataProvider steps_provider
     *
     * @param Step $step Step.
     */
    public function test_outputs_are_typed( Step $step ): void {
        $this->assertIsArray( $step->outputs() );
        foreach ( $step->outputs() as $key => $output ) {
            $this->assertMatchesRegularExpression( '/^[a-z_]+$/', $key );
            $this->assertNotSame( '', $output['label'] );
            $this->assertInstanceOf( Type::class, $output['type'] );
        }
    }

    public function test_outputs_are_what_run_history_shows(): void {
        $outputs = array_map( fn( Step $step ) => array_keys( $step->outputs() ), self::steps() );

        $this->assertSame(
            [
                'order_created'        => [],
                'order_status_changed' => [],
                'condition'            => [ 'result' ],
                'send_internal_email'  => [ 'to' ],
                'change_order_status'  => [ 'from', 'to' ],
                'add_order_note'       => [ 'type' ],
                'update_order_meta'    => [ 'key' ],
            ],
            $outputs
        );
        $this->assertSame(
            [ 'yes', 'no' ],
            array_keys( self::steps()['condition']->outputs()['result']['options'] )
        );
    }

    public function test_condition_is_core_and_email_from_is_a_notice(): void {
        $steps = self::steps();
        $this->assertSame( Plugins::YAYAUTOMATION, $steps['condition']->plugin() );

        $from = $steps['send_internal_email']->fields()[0];
        $this->assertSame( 'from', $from->key() );
        $this->assertSame( Field::NOTICE, $from->type() );
        $this->assertArrayNotHasKey( 'from', $steps['send_internal_email']->sanitize( [ 'from' => 'x@y.z' ] ) );
    }

    public function test_recipients_take_no_variables(): void {
        $recipients = self::steps()['send_internal_email']->fields()[1];
        $this->assertArrayNotHasKey( 'variables', $recipients->to_array() );
    }

    /**
     * @dataProvider steps_provider
     *
     * @param Step $step Step.
     */
    public function test_sanitize_never_fails_and_is_stable( Step $step ): void {
        $junk = [ null, true, 3, 'text', [], [ 'x' ] ];
        foreach ( $step->fields() as $field ) {
            foreach ( [ [ 'x' ], new \stdClass(), true, 7, null ] as $value ) {
                $junk[] = [ $field->key() => $value ];
            }
        }
        foreach ( $junk as $raw ) {
            $config = $step->sanitize( $raw );
            $this->assertSame( $config, $step->sanitize( $config ), 'sanitizing twice changes nothing' );
            $result = $step->validate( $config );
            $this->assertTrue( true === $result || $result instanceof \WP_Error );
        }
    }

    /**
     * @dataProvider steps_provider
     *
     * @param Step $step Step.
     */
    public function test_errors_name_a_field_of_the_step( Step $step ): void {
        $keys = array_map( fn( Field $field ) => $field->key(), $step->fields() );
        $bad  = [
            'order_status_changed' => [ 'to' => [ 'gone' ] ],
            'condition'            => [
                'rules' => [
                    [
                        'field'    => 'order.total',
                        'operator' => 'equal',
                    ],
                ],
            ],
            'send_internal_email'  => [ 'recipients' => 'bad' ],
            'change_order_status'  => [],
            'add_order_note'       => [ 'note' => '' ],
            'update_order_meta'    => [ 'key' => '' ],
        ];
        if ( ! isset( $bad[ $step->id() ] ) ) {
            $this->assertTrue( $step->validate( $step->sanitize( [] ) ) );
            return;
        }

        $error = $step->validate( $step->sanitize( $bad[ $step->id() ] ) );
        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertContains( $error->get_error_data()['field'] ?? null, $keys );
    }

    public function test_status_error_names_the_list_it_is_in(): void {
        $step = new OrderStatusChanged();
        $this->assertSame( 'from', $step->validate( $step->sanitize( [ 'from' => [ 'gone' ] ] ) )->get_error_data()['field'] );
        $this->assertSame( 'to', $step->validate( $step->sanitize( [ 'to' => [ 'gone' ] ] ) )->get_error_data()['field'] );
    }
}
