<?php
namespace YayMatik\Workflow\Core\Email;

use YayMatik\Workflow\Core\Data\Template;
use YayMatik\Workflow\Core\Data\Values;

defined( 'ABSPATH' ) || exit;

/**
 * An `html` field's message, filled in: the body that goes inside the WooCommerce email wrapper.
 */
final class EmailBody {

    /**
     * @param string        $message  HTML, or plain text: what the editor saves for a message without tags.
     * @param Values        $values
     * @param Template|null $template Null = a new one.
     */
    public static function render( string $message, Values $values, ?Template $template = null ): string {
        $template = $template ?? new Template();
        if ( ! self::has_markup( $message, $template ) ) {
            $message = self::text_to_html( $message, $template );
        }
        return $template->render_html( wpautop( $message ), $values );
    }

    /**
     * Whether the text around the tags holds any HTML tag.
     *
     * @param string   $message
     * @param Template $template
     */
    private static function has_markup( string $message, Template $template ): bool {
        foreach ( $template->parse( $message ) as $token ) {
            if ( 'text' === $token['type'] && preg_match( '#</?[a-z][a-z0-9-]*(?:\s[^>]*)?/?>#i', $token['text'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Plain text as HTML: the text escaped, the tags left for the renderer.
     *
     * @param string   $message
     * @param Template $template
     */
    private static function text_to_html( string $message, Template $template ): string {
        $html = '';
        foreach ( $template->parse( $message ) as $token ) {
            $html .= 'text' === $token['type'] ? esc_html( $token['text'] ) : $token['raw'];
        }
        return $html;
    }
}
