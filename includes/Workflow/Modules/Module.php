<?php
namespace YayMatik\Workflow\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * One plugin's part of Workflow Core: its logo, steps and data items.
 *
 * @internal Experimental: may change before Extension API v1.
 */
abstract class Module {

    /** Plugin id, e.g. `woocommerce`; the `plugin` of every step it registers. */
    abstract public function id(): string;

    /** Plugin name in the pickers and on template cards. */
    abstract public function label(): string;

    /** One line on what the plugin's steps are about. */
    abstract public function description(): string;

    /** URL of a one-colour 24×24 SVG the plugin ships; drawn as a mask in the text colour, so its colours never show. */
    abstract public function logo(): string;

    /** Only active plugins' steps are offered. */
    public function active(): bool {
        return true;
    }

    /** @return array Trigger classes (or triggers), in picker order. */
    public function triggers(): array {
        return [];
    }

    /** @return array Condition classes (or conditions), in picker order. */
    public function conditions(): array {
        return [];
    }

    /** @return array Action classes (or actions), in picker order. */
    public function actions(): array {
        return [];
    }

    /** @return string[] Data item classes. */
    public function data_items(): array {
        return [];
    }

    /**
     * Run subjects of this module's own data items about the person with this email, for export and erase.
     *
     * @param string $email
     * @return string[]
     */
    public function privacy_subjects( string $email ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- for modules with their own subjects.
        return [];
    }
}
