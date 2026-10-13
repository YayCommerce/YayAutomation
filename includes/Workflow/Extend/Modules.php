<?php
namespace YayAutomation\Workflow\Extend;

use YayAutomation\Workflow\Steps\Actions\AddOrderNote;
use YayAutomation\Workflow\Steps\Actions\ChangeOrderStatus;
use YayAutomation\Workflow\Steps\Actions\SendInternalEmail;
use YayAutomation\Workflow\Steps\Actions\UpdateOrderMeta;
use YayAutomation\Workflow\Steps\Conditions\Criteria;
use YayAutomation\Workflow\Steps\Triggers\OrderCreated;
use YayAutomation\Workflow\Steps\Triggers\OrderStatusChanged;

defined( 'ABSPATH' ) || exit;

/**
 * Modules that steps, templates and features belong to (decision P1). Each module's tier and
 * on/off switch live here, never in a step: moving a step between Lite and Pro, or keeping an
 * unfinished module out of a release, is a change to its entry.
 *
 * A module that is off is unknown everywhere: its steps are not in the registry, so the editor
 * doesn't offer them, the catalog doesn't list them, a save rejects them and nothing runs them.
 */
final class Modules {

    public const TIER_LITE = 'lite';
    public const TIER_PRO  = 'pro';

    /** Workflow Core's own steps: the order triggers, Condition and the order / email actions. */
    public const CORE = 'core';

    /**
     * Module id => [ 'tier' => TIER_*, 'on' => bool, 'steps' => step classes, in picker order ].
     *
     * @return array[]
     */
    public static function manifest(): array {
        return [
            self::CORE => [
                'tier'  => self::TIER_LITE,
                'on'    => true,
                'steps' => [
                    OrderCreated::class,
                    OrderStatusChanged::class,
                    Criteria::class,
                    SendInternalEmail::class,
                    ChangeOrderStatus::class,
                    AddOrderNote::class,
                    UpdateOrderMeta::class,
                ],
            ],
        ];
    }
}
