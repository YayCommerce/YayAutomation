<?php
namespace YayAutomation\Workflow\Steps\Core;

use YayAutomation\Workflow\Data\DataRegistry;
use YayAutomation\Workflow\Data\Scope;
use YayAutomation\Workflow\Data\Template;
use YayAutomation\Workflow\Data\Variables;

defined( 'ABSPATH' ) || exit;

/**
 * A step that does something, e.g. sends an email. `run()` returns `ok( outputs )`, `failed( error )`,
 * or `skipped( reason )` when there is nothing to act on.
 */
abstract class Action extends Step {

    public const CATEGORY_EMAIL = 'email';
    public const CATEGORY_ORDER = 'order';

    public function kind(): string {
        return self::KIND_ACTION;
    }

    /** Section within the plugin in the picker: one of the CATEGORY_* constants. */
    abstract public function category(): string;

    /** Data items the action needs whatever its config, e.g. `order` to change an order's status. */
    abstract public function requires(): array;

    /**
     * What `requires()` says, plus the data items of every merge tag in the config's text.
     *
     * @param array $config Step config.
     */
    public function uses( array $config ): array {
        $items = $this->requires();
        foreach ( $config as $value ) {
            if ( is_string( $value ) ) {
                $items = array_merge( $items, array_map( [ DataRegistry::class, 'item_of' ], Variables::keys( $value ) ) );
            }
        }
        // Data items only: `steps.*` outputs are checked by the Validator, by the steps above.
        $items = array_intersect( $items, array_keys( DataRegistry::items() ) );
        return array_values( array_diff( array_unique( $items ), DataRegistry::ALWAYS ) );
    }

    public function client_meta(): array {
        return [
            'plugin'   => $this->plugin(),
            'category' => $this->category(),
            'requires' => $this->requires(),
        ];
    }

    /**
     * Text with its merge tags filled in from the Scope. A missing value prints empty.
     *
     * @param string $text  Text with merge tags.
     * @param Scope  $scope Run scope.
     * @param bool   $html  Safe HTML: the text around the tags is escaped too.
     */
    protected static function render( string $text, Scope $scope, bool $html = false ): string {
        static $template = null;
        $template        = $template ?? new Template();
        return $template->render( $text, $scope, $html );
    }
}
