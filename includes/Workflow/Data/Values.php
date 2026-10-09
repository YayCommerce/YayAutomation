<?php
namespace YayAutomation\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Where a template reads its data. The run's `Scope` implements it; tests and previews can pass
 * plain values instead.
 */
interface Values {

    /**
     * Typed value at a path (number, string, bool, list, …), or null when it's missing.
     *
     * @param string $path E.g. `order.total`, `steps.coupon.code`.
     * @return mixed
     */
    public function get( string $path );

    /**
     * How the value reads in a message: safe HTML when `$html`, plain text otherwise (a price with
     * its currency, an option's label, …). Empty when the value is missing.
     *
     * @param string $path E.g. `order.total`.
     * @param bool   $html Safe HTML instead of plain text.
     */
    public function text( string $path, bool $html ): string;
}
