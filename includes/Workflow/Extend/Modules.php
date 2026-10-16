<?php
namespace YayMatik\Workflow\Extend;

use YayMatik\Workflow\Steps\Actions\AddOrderNote;
use YayMatik\Workflow\Steps\Actions\ChangeOrderStatus;
use YayMatik\Workflow\Steps\Actions\GenerateCoupon;
use YayMatik\Workflow\Steps\Actions\SendInternalEmail;
use YayMatik\Workflow\Steps\Actions\UpdateOrderMeta;
use YayMatik\Workflow\Steps\Conditions\Criteria;
use YayMatik\Workflow\Steps\Triggers\OrderCreated;
use YayMatik\Workflow\Steps\Triggers\OrderStatusChanged;

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

    /** Workflow Core's own steps: the order triggers, Condition and the order / email / coupon actions. */
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
                    GenerateCoupon::class,
                ],
            ],
        ];
    }
}
