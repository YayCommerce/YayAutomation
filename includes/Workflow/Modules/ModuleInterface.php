<?php
namespace YayMatik\Workflow\Modules;

use YayMatik\Workflow\Data\DataItem;

defined( 'ABSPATH' ) || exit;

/**
 * One plugin's part of Workflow Core: its logo, tier, on/off switch, steps and data items
 * (decision P1). Its id is what its steps return from `plugin()`. Tiers live here, never in a step.
 *
 * Core's are `WoocommerceModule` and `MatikModule`. Another plugin adds its own on
 * `ModuleRegister::HOOK` (see there).
 */
interface ModuleInterface {

    public const TIER_LITE = 'lite';
    public const TIER_PRO  = 'pro';

    /** Opening tag every logo shares: 24×24, outline in the current text color. */
    public const SVG_OPEN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';

    /** Plugin id, e.g. `woocommerce`. */
    public function id(): string;

    /** Plugin name in the pickers and on template cards. */
    public function label(): string;

    /** One line on what the plugin's steps are about. */
    public function description(): string;

    /**
     * Our own simple line drawing that marks the plugin, never a copy of its official mark:
     * starts with `SVG_OPEN`, `currentColor` only, tags from `ModuleRegister::LOGO_TAGS`.
     */
    public function logo(): string;

    /** Whether the plugin is running. Only active plugins' steps are offered. */
    public function active(): bool;

    /** `TIER_LITE` or `TIER_PRO`. */
    public function tier(): string;

    /** Off: the module's steps and data are unknown everywhere. */
    public function enabled(): bool;

    /**
     * Step classes, in picker order. Each is built with no arguments.
     *
     * @return string[]
     */
    public function steps(): array;

    /** @return DataItem[] */
    public function data_items(): array;
}
