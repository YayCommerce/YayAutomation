<?php
namespace YayMatik\Workflow\Actions;

use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;

defined( 'ABSPATH' ) || exit;

/** Pauses the run, then goes on with the next step; steps after it read fresh data. */
class Wait extends Action {

    public const ID = 'wait';

    /** Seconds per unit; fixed, so a day is always 24 hours. */
    public const SECONDS = [
        'minutes' => 60,
        'hours'   => 3600,
        'days'    => 86400,
        'weeks'   => 604800,
    ];

    /** At most a year, in each unit. */
    public const MAX = [
        'minutes' => 525600,
        'hours'   => 8760,
        'days'    => 365,
        'weeks'   => 52,
    ];

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Wait', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Pause the workflow for a while before the next step.', 'yaymatik' );
    }

    public function icon(): string {
        return 'clock';
    }

    public function category(): string {
        return self::CATEGORY_FLOW;
    }

    public function requires(): array {
        return [];
    }

    public function fields(): array {
        return [
            Field::number_unit( 'delay', __( 'Wait for', 'yaymatik' ), [ self::class, 'units' ] )
                ->default(
                    [
                        'value' => 1,
                        'unit'  => 'days',
                    ]
                )
                ->help( __( 'Then the workflow continues with the next step.', 'yaymatik' ) ),
        ];
    }

    public function summary(): string {
        /* translators: %delay%: how long, e.g. "7 days"; keep the placeholder as it is. */
        return __( 'Wait %delay%', 'yaymatik' );
    }

    public function validate( array $config ) {
        $error = $this->field_error( $config );
        if ( $error ) {
            return $error;
        }
        $value = $config['delay']['value'];
        if ( $value < 1 || floor( $value ) != $value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- 3.0 is a whole number.
            return $this->invalid( __( 'Enter a whole number, 1 or more.', 'yaymatik' ), 'delay' );
        }
        if ( $value > self::MAX[ $config['delay']['unit'] ] ) {
            return $this->invalid( __( 'Wait at most a year.', 'yaymatik' ), 'delay' );
        }
        return true;
    }

    public function run( Scope $scope, array $config ): StepResult {
        $delay = (int) $config['delay']['value'] * self::SECONDS[ $config['delay']['unit'] ];
        return StepResult::wait( time() + $delay );
    }

    public static function units(): array {
        return [
            'minutes' => __( 'Minutes', 'yaymatik' ),
            'hours'   => __( 'Hours', 'yaymatik' ),
            'days'    => __( 'Days', 'yaymatik' ),
            'weeks'   => __( 'Weeks', 'yaymatik' ),
        ];
    }
}
