<?php
namespace YayAutomation\Engine\Blocks\Core;

use YayAutomation\Engine\Blocks\Actions\SendAdminEmail;
use YayAutomation\Engine\Blocks\Triggers\OrderStatusChanged;
use YayAutomation\Engine\Blocks\Conditions\OrderTotal;

defined( 'ABSPATH' ) || exit;

/** Every block the engine knows, by id. */
final class BlockRegistry {

    /** @var Block[]|null Keyed by block id. */
    private static $blocks = null;

    /** @return Block[] */
    public static function all(): array {
        if ( null === self::$blocks ) {
            self::$blocks = [];
            foreach ( [ new OrderStatusChanged(), new OrderTotal(), new SendAdminEmail() ] as $block ) {
                self::$blocks[ $block->id() ] = $block;
            }
        }
        return self::$blocks;
    }

    public static function get( string $id ): ?Block {
        return self::all()[ $id ] ?? null;
    }
}
