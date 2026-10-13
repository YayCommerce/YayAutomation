<?php
namespace YayAutomation\Workflow\Steps\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The step icons the admin app can draw: lucide icon names. A step's `icon()` returns one of them,
 * never SVG or a URL. A step that needs another icon gets it added here (and to the admin app's
 * icon map), not shipped by its plugin.
 */
final class StepIcons {

    public const NAMES = [
        // Orders and store.
        'shopping-cart',
        'shopping-bag',
        'package',
        'package-check',
        'receipt',
        'credit-card',
        'truck',
        'store',
        // Changes and flow.
        'refresh-cw',
        'arrow-right-left',
        'split',
        'filter',
        'git-branch',
        'list-checks',
        'repeat',
        // Messages.
        'mail',
        'send',
        'message-square',
        'bell',
        'sticky-note',
        'file-text',
        'pencil',
        // Data.
        'database',
        'tag',
        'link',
        'webhook',
        'globe',
        // Discounts and rewards.
        'ticket-percent',
        'badge-percent',
        'gift',
        'coins',
        'star',
        'heart',
        // People.
        'user',
        'user-plus',
        'users',
        // Time.
        'clock',
        'hourglass',
        'calendar',
        'timer',
        // Other.
        'zap',
    ];

    /**
     * @param string $name Icon name.
     */
    public static function exists( string $name ): bool {
        return in_array( $name, self::NAMES, true );
    }
}
