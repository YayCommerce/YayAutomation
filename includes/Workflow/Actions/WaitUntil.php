<?php
namespace YayMatik\Workflow\Actions;

use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;

defined( 'ABSPATH' ) || exit;

/** Pauses the run until the next chosen time, in store time, on one of the chosen days. */
class WaitUntil extends Action {

    public const ID = 'wait_until';

    /** 'HH:MM', 00:00–23:59. */
    private const TIME = '/^(?:[01]\d|2[0-3]):[0-5]\d$/D';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Wait until', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Pause until a time of day, on the days you choose.', 'yaymatik' );
    }

    public function icon(): string {
        return 'history';
    }

    public function category(): string {
        return self::CATEGORY_FLOW;
    }

    public function requires(): array {
        return [];
    }

    public function fields(): array {
        return [
            // Blank by default: a config without a valid time stays an issue to fix, never 09:00. The editor starts new steps at 09:00.
            Field::text( 'time', __( 'Time', 'yaymatik' ) )
                ->help( __( 'Runs resume when WordPress next runs its scheduled tasks, so it can be a few minutes later on a quiet store.', 'yaymatik' ) ),
            Field::multiselect( 'weekdays', __( 'Days', 'yaymatik' ), [ self::class, 'weekdays' ] )
                ->placeholder( __( 'Any day', 'yaymatik' ) )
                ->help( __( 'Leave empty for any day.', 'yaymatik' ) ),
        ];
    }

    public function summary(): string {
        /* translators: %time%: a time, e.g. "9:15 AM", %weekdays%: days, e.g. "Mon–Fri"; keep the placeholders as they are. */
        return __( 'Until %time% · %weekdays%', 'yaymatik' );
    }

    public function outputs(): array {
        return [
            'until' => [
                'label' => __( 'Waited until', 'yaymatik' ),
                'type'  => Type::date(),
            ],
        ];
    }

    /** ISO day number '1' (Monday) – '7' => its short name. */
    public static function weekdays(): array {
        return [
            '1' => __( 'Mon', 'yaymatik' ),
            '2' => __( 'Tue', 'yaymatik' ),
            '3' => __( 'Wed', 'yaymatik' ),
            '4' => __( 'Thu', 'yaymatik' ),
            '5' => __( 'Fri', 'yaymatik' ),
            '6' => __( 'Sat', 'yaymatik' ),
            '7' => __( 'Sun', 'yaymatik' ),
        ];
    }

    /**
     * A time not in 'HH:MM', blank included, gets this message rather than the generic one.
     *
     * @param array $config
     */
    public function validate( array $config ) {
        if ( ! preg_match( self::TIME, $config['time'] ) ) {
            return $this->invalid( __( 'Enter a time, e.g. 9:15 am.', 'yaymatik' ), 'time' );
        }
        return $this->field_error( $config ) ?? true;
    }

    public function run( Scope $scope, array $config ): StepResult {
        $zone                  = wp_timezone();
        list( $hour, $minute ) = array_map( 'intval', explode( ':', $config['time'] ) );
        $until                 = self::next_time( time(), $hour, $minute, array_map( 'intval', $config['weekdays'] ), $zone );
        return StepResult::wait( $until, [ 'until' => ( new \DateTimeImmutable( '@' . $until ) )->setTimezone( $zone )->format( 'Y-m-d H:i' ) ] );
    }

    /**
     * The first `hour:minute` later than `$now` on a chosen ISO weekday (any day when none), in `$zone`.
     * Stepped by calendar day, so a DST change keeps the wall-clock time; a time in a DST gap moves forward, a time that occurs twice takes the first.
     *
     * @param int           $now
     * @param int           $hour
     * @param int           $minute
     * @param int[]         $days
     * @param \DateTimeZone $zone
     */
    public static function next_time( int $now, int $hour, int $minute, array $days, \DateTimeZone $zone ): int {
        $today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );
        // Today and the next 7 days hold every weekday, today's time included.
        for ( $offset = 0; $offset <= 7; $offset++ ) {
            // Parsed from the date, not setTime(): setTime() keeps the day's offset, so a repeated time would read as the second one.
            $at = new \DateTimeImmutable( $today->modify( "+{$offset} days" )->format( 'Y-m-d' ) . sprintf( ' %02d:%02d', $hour, $minute ), $zone );
            if ( $at->getTimestamp() > $now && ( ! $days || in_array( (int) $at->format( 'N' ), $days, true ) ) ) {
                return $at->getTimestamp();
            }
        }
        return $now;
    }
}
