<?php
namespace YayMatik\Workflow\Actions;

use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;

defined( 'ABSPATH' ) || exit;

/** Pauses the run until the next chosen hour, in store time, on one of the chosen days. */
class WaitUntil extends Action {

    public const ID = 'wait_until';

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
            Field::select( 'hour', __( 'Time', 'yaymatik' ), [ self::class, 'hours' ] )->default( '9' ),
            Field::multiselect( 'weekdays', __( 'Days', 'yaymatik' ), [ self::class, 'weekdays' ] )
                ->placeholder( __( 'Any day', 'yaymatik' ) )
                ->help( __( 'Leave empty for any day.', 'yaymatik' ) ),
        ];
    }

    public function summary(): string {
        /* translators: %hour%: a time, e.g. "9:00 AM", %weekdays%: days, e.g. "Mon–Fri"; keep the placeholders as they are. */
        return __( 'Until %hour% · %weekdays%', 'yaymatik' );
    }

    public function outputs(): array {
        return [
            'until' => [
                'label' => __( 'Waited until', 'yaymatik' ),
                'type'  => Type::date(),
            ],
        ];
    }

    /** '0'–'23' => the hour in the site's time format, e.g. "9:00 AM". */
    public static function hours(): array {
        $format = (string) get_option( 'time_format', 'g:i a' );
        $utc    = new \DateTimeZone( 'UTC' );
        $hours  = [];
        for ( $hour = 0; $hour < 24; $hour++ ) {
            $hours[ (string) $hour ] = wp_date( $format, $hour * 3600, $utc );
        }
        return $hours;
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

    public function run( Scope $scope, array $config ): StepResult {
        $zone  = wp_timezone();
        $until = self::next_time( time(), (int) $config['hour'], array_map( 'intval', $config['weekdays'] ), $zone );
        return StepResult::wait( $until, [ 'until' => ( new \DateTimeImmutable( '@' . $until ) )->setTimezone( $zone )->format( 'Y-m-d H:i' ) ] );
    }

    /**
     * The first `hour:00` later than `$now` on a chosen ISO weekday (any day when none), in `$zone`.
     * Stepped by calendar day, so a DST change keeps the wall-clock hour; an hour in a DST gap moves forward, an hour that occurs twice takes the first.
     *
     * @param int           $now
     * @param int           $hour
     * @param int[]         $days
     * @param \DateTimeZone $zone
     */
    public static function next_time( int $now, int $hour, array $days, \DateTimeZone $zone ): int {
        $today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );
        // Today and the next 7 days hold every weekday, today's hour included.
        for ( $offset = 0; $offset <= 7; $offset++ ) {
            // Parsed from the date, not setTime(): setTime() keeps the day's offset, so a repeated hour would read as the second one.
            $at = new \DateTimeImmutable( $today->modify( "+{$offset} days" )->format( 'Y-m-d' ) . sprintf( ' %02d:00', $hour ), $zone );
            if ( $at->getTimestamp() > $now && ( ! $days || in_array( (int) $at->format( 'N' ), $days, true ) ) ) {
                return $at->getTimestamp();
            }
        }
        return $now;
    }
}
