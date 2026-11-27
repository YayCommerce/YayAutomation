<?php
/**
 * YayMagic's fake adapter: designs kept in an option, a minimal editor page, no YayMagic code.
 *
 * @package YayMagic
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- another plugin: its own text domain.

/** Stands in for YayMagic until its real adapter is written. */
final class YayMagic_YayMatik_Fake_Adapter {

    /** Designs saved in the editor: id => [ name, body ]. */
    const DESIGNS = 'yaymagic_fake_designs';

    const EDITOR = 'yaymagic-editor';

    /** Designs every site starts with: id => [ name, body ]. */
    const SAMPLES = [
        1 => [
            'name' => 'Welcome',
            'body' => '<p>Hi {{ customer.first_name | default: "there" }},</p><p>YayMagic design: Welcome</p><p>{{ site.shop_button }}</p>',
        ],
        2 => [
            'name' => 'We miss you',
            'body' => '<p>Hi {{ customer.first_name | default: "there" }},</p><p>YayMagic design: We miss you</p><p>{{ site.shop_button }}</p>',
        ],
        3 => [
            'name' => 'Milestone',
            'body' => '<p>Hi {{ customer.first_name | default: "there" }},</p><p>YayMagic design: Milestone</p><p>{{ site.shop_button }}</p>',
        ],
    ];

    /** YayMagic's own behavior: it wraps every email in its design, but not one YayMatik marked. */
    public function hook_mail(): void {
        add_filter( 'wp_mail', [ $this, 'wrap' ] );
    }

    /** YayMagic's editor page: no menu entry, YayMatik opens it in its modal. */
    public function hook_editor(): void {
        add_action( 'admin_menu', [ $this, 'add_editor' ] );
    }

    /**
     * @param array $atts
     */
    public function wrap( $atts ) {
        if ( '' === self::yaymatik_mark( $atts['headers'] ?? '' ) ) {
            $atts['message'] = '<html><body data-wrapper="yaymagic">' . $atts['message'] . '</body></html>';
        }
        return $atts;
    }

    /** Id => [ name, body ]; a saved design replaces the sample with its id. */
    public function designs(): array {
        $designs = (array) get_option( self::DESIGNS, [] ) + self::SAMPLES;
        ksort( $designs );
        return $designs;
    }

    /** Design id => name. */
    public function design_options(): array {
        return array_map( fn( array $design ) => $design['name'], $this->designs() );
    }

    /**
     * Updates design `$id`, or adds one when there is none; returns its id.
     *
     * @param int    $id
     * @param string $name
     * @param string $body
     */
    public function save_design( int $id, string $name, string $body ): int {
        $designs = $this->designs();
        $id      = isset( $designs[ $id ] ) ? $id : max( array_keys( $designs ) ) + 1;
        $saved   = (array) get_option( self::DESIGNS, [] );

        $saved[ $id ] = [
            'name' => $name,
            'body' => $body,
        ];
        update_option( self::DESIGNS, $saved, false );
        return $id;
    }

    /**
     * Subject and HTML for any trigger, with or without an order; YayMatik fills the merge tags and wraps it.
     *
     * @param array              $config
     * @param \YayMatik\Api\Step $step
     */
    public function build_email( array $config, $step ): array {
        $design = $this->designs()[ (int) ( $config['design_id'] ?? 0 ) ] ?? null;
        if ( ! $design ) {
            throw new \RuntimeException( 'No YayMagic design ' . (int) ( $config['design_id'] ?? 0 ) . '.' );
        }
        return [
            'subject' => (string) $config['subject'],
            'html'    => $design['body'],
            'wrap'    => true,
        ];
    }

    /**
     * A design with order blocks needs an order: the fake reads any `{{ order.… }}` tag as one.
     *
     * @param array $config
     * @param array $available
     */
    public function validate( array $config, array $available ): array {
        $design = $this->designs()[ (int) ( $config['design_id'] ?? 0 ) ] ?? null;
        if ( $design && preg_match( '/\{\{\s*order\./', $design['body'] ) && ! in_array( 'order', $available, true ) ) {
            return [ 'design_id' => __( 'This design shows order details, but this workflow’s trigger has no order.', 'yaymagic' ) ];
        }
        return [];
    }

    /**
     * The editor page for the step's design; YayMatik adds its embed and channel args.
     *
     * @param array $context
     */
    public function editor_url( array $context ): string {
        return admin_url( 'admin.php?page=' . self::EDITOR . '&design=' . rawurlencode( (string) $context['value'] ) );
    }

    public function add_editor(): void {
        $hook = add_submenu_page( 'options.php', 'YayMagic editor', 'YayMagic editor', 'manage_woocommerce', self::EDITOR, [ $this, 'render_editor' ] );
        add_action( "load-{$hook}", [ $this, 'save_from_editor' ] );
    }

    public function render_editor(): void {
        require __DIR__ . '/editor.php';
    }

    /** The editor's form post: saves, then reloads the page with `saved` so it can post `save` to YayMatik. */
    public function save_from_editor(): void {
        if ( empty( $_POST ) ) {
            return;
        }
        check_admin_referer( 'yaymagic_design' );
        $id   = $this->save_design(
            absint( $_POST['design'] ?? 0 ),
            sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
            wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) )
        );
        $back = remove_query_arg( [ 'design', 'saved' ], esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );
        wp_safe_redirect(
            add_query_arg(
                [
                    'design' => $id,
                    'saved'  => $id,
                ],
                $back
            )
        );
        exit;
    }

    /**
     * The `X-YayMatik-Mark` header in `wp_mail()` headers: a string, lines, or after sending its parsed name => value array.
     *
     * @param mixed $headers
     */
    private static function yaymatik_mark( $headers ): string {
        $lines = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
        foreach ( $lines as $name => $line ) {
            $parts = is_string( $name ) ? [ $name, $line ] : explode( ':', (string) $line, 2 );
            if ( 2 === count( $parts ) && 0 === strcasecmp( trim( $parts[0] ), 'X-YayMatik-Mark' ) ) {
                return trim( (string) $parts[1] );
            }
        }
        return '';
    }
}
