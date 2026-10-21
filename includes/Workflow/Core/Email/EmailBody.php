<?php
namespace YayMatik\Workflow\Core\Email;

use YayMatik\Workflow\Core\Data\Template;
use YayMatik\Workflow\Core\Data\Values;

defined( 'ABSPATH' ) || exit;

/**
 * An email message from an `html` field, filled in: the HTML body that goes inside the WooCommerce
 * email wrapper. Paragraphs come from `wpautop()`; values are escaped by `Template::render_html()`.
 */
final class EmailBody {

    /**
     * @param string        $message  Stored message: HTML, or plain text saved before the field was rich.
     * @param Values        $values   Where the data comes from.
     * @param Template|null $template Renderer; null = a new one.
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
     * @param string   $message  Stored message.
     * @param Template $template Parser.
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
     * @param string   $message  Plain text with merge tags.
     * @param Template $template Parser.
     */
    private static function text_to_html( string $message, Template $template ): string {
        $html = '';
        foreach ( $template->parse( $message ) as $token ) {
            $html .= 'text' === $token['type'] ? esc_html( $token['text'] ) : $token['raw'];
        }
        return $html;
    }
}
