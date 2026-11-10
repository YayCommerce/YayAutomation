<?php
namespace YayMatik\Workflow\Core\Steps;

defined( 'ABSPATH' ) || exit;

/**
 * The step icons the admin app can draw: lucide names, never SVG or a URL.
 */
final class StepIcons {

    public const NAMES = [
        // Orders and store.
        'shopping-cart',
        'shopping-bag',
        'package',
        'package-check',
        'circle-check',
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
        'list',
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
        'ticket',
        'ticket-percent',
        'badge-percent',
        'gift',
        'coins',
        'star',
        'heart',
        'heart-pulse',
        'activity',
        // People.
        'user',
        'user-plus',
        'users',
        // Time.
        'clock',
        'hourglass',
        'calendar',
        'timer',
        'history',
        // Other.
        'zap',
    ];
}
