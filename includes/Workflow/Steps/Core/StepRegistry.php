<?php
namespace YayAutomation\Workflow\Steps\Core;

use YayAutomation\Workflow\Steps\Actions\AddOrderNote;
use YayAutomation\Workflow\Steps\Actions\ChangeOrderStatus;
use YayAutomation\Workflow\Steps\Actions\SendInternalEmail;
use YayAutomation\Workflow\Steps\Actions\UpdateOrderMeta;
use YayAutomation\Workflow\Steps\Conditions\Criteria;
use YayAutomation\Workflow\Steps\Triggers\OrderCreated;
use YayAutomation\Workflow\Steps\Triggers\OrderStatusChanged;

defined( 'ABSPATH' ) || exit;

/** Every step the engine knows, by id. To add one, write its class and list it here. */
final class StepRegistry {

    /** @var Step[]|null Keyed by step id. */
    private static $steps = null;

    /** @return Step[] */
    public static function all(): array {
        if ( null === self::$steps ) {
            self::$steps = [];
            $list        = [
                new OrderCreated(),
                new OrderStatusChanged(),
                new Criteria(),
                new SendInternalEmail(),
                new ChangeOrderStatus(),
                new AddOrderNote(),
                new UpdateOrderMeta(),
            ];
            foreach ( $list as $step ) {
                self::$steps[ $step->id() ] = $step;
            }
        }
        return self::$steps;
    }

    public static function get( string $id ): ?Step {
        return self::all()[ $id ] ?? null;
    }

    /** @return Trigger[] */
    public static function triggers(): array {
        return array_filter( self::all(), fn( Step $step ) => $step instanceof Trigger );
    }

    /** Shape must match `StepMeta` in apps/admin-page/src/types/localize.d.ts. */
    public static function to_client(): array {
        $meta = [];
        foreach ( self::all() as $id => $step ) {
            $meta[ $id ] = [ 'kind' => $step->kind() ] + $step->client_meta();
        }
        return $meta;
    }
}
