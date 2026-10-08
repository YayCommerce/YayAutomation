<?php
namespace YayAutomation\Engine\Blocks\Core;

use YayAutomation\Engine\Data\DataRegistry;
use YayAutomation\Engine\Data\Variables;

defined( 'ABSPATH' ) || exit;

/** A step that does something, e.g. sends an email. */
abstract class Action extends Block {

    public const CATEGORY_EMAIL = 'email';
    public const CATEGORY_ORDER = 'order';

    public function kind(): string {
        return self::KIND_ACTION;
    }

    /** Plugin the action is grouped under in the picker (see Engine/Plugins). */
    abstract public function plugin(): string;

    /** Section within the plugin in the picker: one of the CATEGORY_* constants. */
    abstract public function category(): string;

    /** Data items the action needs whatever its config, e.g. `order` to change an order's status. */
    abstract public function requires(): array;

    /**
     * Performs the step. A WP_Error is logged; the workflow continues with the next step.
     *
     * @param array $context Run context, see Block.
     * @param array $config  Sanitized, valid config.
     * @return true|\WP_Error
     */
    abstract public function run( array $context, array $config );

    /**
     * What `requires()` says, plus the items of every merge tag in the config's text.
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
     * Cleans text that may hold merge tags. Not sanitize_text(area)_field(): those treat
     * `<{{ customer.email }}>` as a tag and strip it. Output is escaped where it's used.
     *
     * @param string $text Raw text.
     */
    protected static function plain_text( string $text ): string {
        $text = str_replace( "\r\n", "\n", wp_check_invalid_utf8( $text ) );
        // Control characters, keeping tab and newline.
        return trim( (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', $text ) );
    }

    /**
     * `plain_text()` on one line: line breaks and tabs become spaces (e.g. so a subject can't inject mail headers).
     *
     * @param string $text Raw text.
     */
    protected static function single_line( string $text ): string {
        return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', self::plain_text( $text ) ) );
    }

    /**
     * Error for the first merge tag no data item defines, or null when all are known.
     *
     * @param string ...$texts Config text fields.
     */
    protected function unknown_variable( string ...$texts ): ?\WP_Error {
        foreach ( $texts as $text ) {
            $unknown = Variables::unknown( $text );
            if ( $unknown ) {
                /* translators: %s: merge tag, e.g. {{ order.foo }} */
                return $this->invalid( sprintf( __( 'Unknown variable: %s', 'yay-automation' ), '{{ ' . $unknown[0] . ' }}' ) );
            }
        }
        return null;
    }
}
