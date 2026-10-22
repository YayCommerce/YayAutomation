<?php
namespace YayMatik\Workflow\Core\Steps;

use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Template;
use YayMatik\Workflow\Core\Data\Values;

defined( 'ABSPATH' ) || exit;

/**
 * A step that does something, e.g. sends an email.
 */
abstract class Action extends Step {

    public const CATEGORY_EMAIL  = 'email';
    public const CATEGORY_ORDER  = 'order';
    public const CATEGORY_COUPON = 'coupon';
    public const CATEGORY_FLOW   = 'flow';

    public function kind(): string {
        return self::KIND_ACTION;
    }

    /** Picker section: a CATEGORY_* constant. */
    abstract public function category(): string;

    /** Data items the action needs whatever its config, e.g. `order` to change an order's status. */
    abstract public function requires(): array;

    /**
     * The data items in `requires()`; the Validator checks what merge tags read.
     *
     * @param array $config
     */
    public function uses( array $config ): array {
        $items = array_intersect( $this->requires(), array_keys( DataRegistry::items() ) );
        return array_values( array_diff( array_unique( $items ), DataRegistry::ALWAYS ) );
    }

    public function client_meta(): array {
        return [
            'category' => $this->category(),
            'requires' => $this->requires(),
        ];
    }

    /**
     * Text with its merge tags filled in; a missing value prints empty.
     *
     * @param string $text
     * @param Values $scope
     * @param bool   $html Safe HTML: the text around the tags is escaped too.
     */
    protected static function render( string $text, Values $scope, bool $html = false ): string {
        static $template = null;
        $template        = $template ?? new Template();
        return $template->render( $text, $scope, $html );
    }
}
