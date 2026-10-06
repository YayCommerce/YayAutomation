<?php
namespace YayAutomation\Engine\Blocks\Core;

use YayAutomation\Engine\Blocks\Actions\AddOrderNote;
use YayAutomation\Engine\Blocks\Actions\ChangeOrderStatus;
use YayAutomation\Engine\Blocks\Actions\SendInternalEmail;
use YayAutomation\Engine\Blocks\Actions\UpdateOrderMeta;
use YayAutomation\Engine\Blocks\Conditions\Criteria;
use YayAutomation\Engine\Blocks\Triggers\OrderCreated;
use YayAutomation\Engine\Blocks\Triggers\OrderStatusChanged;

defined( 'ABSPATH' ) || exit;

/** Every block the engine knows, by id. To add one, write its class and list it here. */
final class BlockRegistry {

    /** @var Block[]|null Keyed by block id. */
    private static $blocks = null;

    /** @return Block[] */
    public static function all(): array {
        if ( null === self::$blocks ) {
            self::$blocks = [];
            $list         = [
                new OrderCreated(),
                new OrderStatusChanged(),
                new Criteria(),
                new SendInternalEmail(),
                new ChangeOrderStatus(),
                new AddOrderNote(),
                new UpdateOrderMeta(),
            ];
            foreach ( $list as $block ) {
                self::$blocks[ $block->id() ] = $block;
            }
        }
        return self::$blocks;
    }

    public static function get( string $id ): ?Block {
        return self::all()[ $id ] ?? null;
    }

    /** @return Trigger[] */
    public static function triggers(): array {
        return array_filter( self::all(), fn( Block $block ) => $block instanceof Trigger );
    }

    /** Shape must match `BlockMeta` in apps/admin-page/src/types/localize.d.ts. */
    public static function to_client(): array {
        $meta = [];
        foreach ( self::all() as $id => $block ) {
            $meta[ $id ] = [ 'kind' => $block->kind() ] + $block->client_meta();
        }
        return $meta;
    }
}
