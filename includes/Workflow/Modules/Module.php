<?php
namespace YayMatik\Workflow\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * One plugin's part of Workflow Core: its logo, steps and data items. Its id is the `plugin` of
 * every step it registers. Free vs Pro is the folder a module lives in, never a check in a step.
 */
abstract class Module {

    /** Plugin id, e.g. `woocommerce`. */
    abstract public function id(): string;

    /** Plugin name in the pickers and on template cards. */
    abstract public function label(): string;

    /** One line on what the plugin's steps are about. */
    abstract public function description(): string;

    /** URL of a one-colour 24×24 SVG the plugin ships; drawn as a mask in the text colour, so its colours never show. */
    abstract public function logo(): string;

    /** Whether the plugin is running. Only active plugins' steps are offered. */
    public function active(): bool {
        return true;
    }

    /** @return string[] Trigger classes, in picker order. */
    public function triggers(): array {
        return [];
    }

    /** @return string[] Condition classes, in picker order. */
    public function conditions(): array {
        return [];
    }

    /** @return string[] Action classes, in picker order. */
    public function actions(): array {
        return [];
    }

    /** @return string[] Data item classes. */
    public function data_items(): array {
        return [];
    }
}
