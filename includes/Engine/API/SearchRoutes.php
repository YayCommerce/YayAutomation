<?php
namespace YayMatik\Engine\API;

use YayMatik\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/** GET /search/products, /search/categories, /search/coupons: paged lists for rule values, never sent in `yayMatikMeta`. */
class SearchRoutes {

    use SingletonTrait;

    private const PER_PAGE = 20;

    /** Only titles are read, so their meta and terms aren't loaded. */
    private const TITLES_ONLY = [
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ];

    protected function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route(
            RestAPI::REST_NAMESPACE,
            '/search/(?P<type>products|categories|coupons)',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'search' ],
                'permission_callback' => [ RestAPI::get_instance(), 'can_manage' ],
                'args'                => [
                    'term'    => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'page'    => [
                        'type'    => 'integer',
                        'minimum' => 1,
                        'default' => 1,
                    ],
                    // The exact items of a saved rule, for their labels; `term` and `page` don't apply.
                    'include' => [
                        'type'     => 'array',
                        'items'    => [ 'type' => 'string' ],
                        'default'  => [],
                        'maxItems' => 100,
                    ],
                ],
            ]
        );
    }

    /**
     * One page: `{ items: [ { value, label, hint? } ], hasMore }`.
     *
     * @param \WP_REST_Request $request
     */
    public function search( \WP_REST_Request $request ) {
        $type    = (string) $request['type'];
        $include = array_values( array_filter( array_map( 'sanitize_text_field', (array) $request['include'] ), 'strlen' ) );
        if ( $include ) {
            return rest_ensure_response(
                [
                    'items'   => $this->{ 'included_' . $type }( $include ),
                    'hasMore' => false,
                ]
            );
        }
        // One more than a page tells whether there is another, without counting them all.
        $items = $this->{ 'find_' . $type }( (string) $request['term'], (int) $request['page'] );
        return rest_ensure_response(
            [
                'items'   => array_slice( $items, 0, self::PER_PAGE ),
                'hasMore' => count( $items ) > self::PER_PAGE,
            ]
        );
    }

    /**
     * Variations are left out: a rule on a product matches its variations through the parent.
     *
     * @param string $term
     * @param int    $page
     */
    private function find_products( string $term, int $page ): array {
        return $this->post_items(
            [
                'post_type'   => 'product',
                'post_status' => [ 'publish', 'private', 'draft' ],
                's'           => $term,
            ],
            $page
        );
    }

    /**
     * @param string[] $values
     */
    private function included_products( array $values ): array {
        return array_map(
            [ $this, 'post_item' ],
            get_posts(
                [
                    'post_type'      => 'product',
                    'post_status'    => 'any',
                    'post__in'       => array_map( 'intval', $values ),
                    'orderby'        => 'post__in',
                    'posts_per_page' => count( $values ),
                ] + self::TITLES_ONLY
            )
        );
    }

    /**
     * @param string $term
     * @param int    $page
     */
    private function find_categories( string $term, int $page ): array {
        return $this->term_items(
            [
                // Names are stored with `&` as `&amp;`.
                'search' => _wp_specialchars( $term ),
                'number' => self::PER_PAGE + 1,
                'offset' => ( $page - 1 ) * self::PER_PAGE,
            ]
        );
    }

    /**
     * @param string[] $values
     */
    private function included_categories( array $values ): array {
        return $this->term_items( [ 'include' => array_map( 'intval', $values ) ] );
    }

    /**
     * @param string $term
     * @param int    $page
     */
    private function find_coupons( string $term, int $page ): array {
        return array_map(
            fn( array $item ) => self::coupon_item( $item['label'] ),
            $this->post_items(
                [
                    'post_type'   => 'shop_coupon',
                    'post_status' => 'publish',
                    's'           => $term,
                ],
                $page
            )
        );
    }

    /**
     * @param string[] $values Coupon codes.
     */
    private function included_coupons( array $values ): array {
        $found = array_filter( $values, fn( string $code ) => (bool) wc_get_coupon_id_by_code( $code ) );
        return array_values( array_map( [ self::class, 'coupon_item' ], $found ) );
    }

    /**
     * @param array $query
     * @param int   $page
     */
    private function post_items( array $query, int $page ): array {
        $found = get_posts(
            $query + [
                // ID too: with equal titles, offset paging could repeat one row and skip another.
                'orderby'        => [
                    'title' => 'ASC',
                    'ID'    => 'ASC',
                ],
                'posts_per_page' => self::PER_PAGE + 1,
                'offset'         => ( $page - 1 ) * self::PER_PAGE,
            ] + self::TITLES_ONLY
        );
        return array_map( [ $this, 'post_item' ], $found );
    }

    /**
     * @param \WP_Post $post
     */
    private function post_item( \WP_Post $post ): array {
        return [
            'value' => (string) $post->ID,
            'label' => self::plain( $post->post_title ),
            // Not every product on the list is on sale.
            'hint'  => 'publish' === $post->post_status ? '#' . $post->ID : get_post_status_object( $post->post_status )->label,
        ];
    }

    /**
     * A rule compares codes, so the code is both value and label.
     *
     * @param string $title
     */
    private static function coupon_item( string $title ): array {
        $code = wc_format_coupon_code( $title );
        return [
            'value' => $code,
            'label' => $code,
        ];
    }

    /**
     * "Men › Shoes": two categories can share a name under different parents.
     *
     * @param \WP_Term $term
     */
    private static function category_path( \WP_Term $term ): string {
        $names = [ self::plain( $term->name ) ];
        foreach ( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) as $ancestor ) {
            array_unshift( $names, self::plain( (string) get_term_field( 'name', $ancestor, 'product_cat', 'raw' ) ) );
        }
        return implode( ' › ', $names );
    }

    /**
     * A stored name as React should show it.
     *
     * @param string $name
     */
    private static function plain( string $name ): string {
        return html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
    }

    /**
     * @param array $query
     */
    private function term_items( array $query ): array {
        $terms = get_terms(
            $query + [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'orderby'    => 'name',
            ]
        );
        if ( ! is_array( $terms ) ) {
            return [];
        }
        return array_map(
            fn( \WP_Term $term ) => [
                'value' => (string) $term->term_id,
                'label' => self::category_path( $term ),
                'hint'  => '#' . $term->term_id,
            ],
            $terms
        );
    }
}
