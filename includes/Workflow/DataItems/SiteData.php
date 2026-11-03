<?php
namespace YayMatik\Workflow\DataItems;

use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Email\EmailButton;
use YayMatik\Workflow\Core\Email\ProductGrid;

defined( 'ABSPATH' ) || exit;

/** The WordPress site; always available, with no context object. */
final class SiteData extends DataItem {

    public const ID = 'site';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Site', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Your WordPress site: its name, address, shop and account pages, and email blocks for them. Available in every workflow.', 'yaymatik' );
    }

    public function icon(): string {
        return 'globe';
    }

    protected function define(): array {
        return [
            'name'           => [
                'label' => __( 'Site name', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn() => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            ],
            'url'            => [
                'label'       => __( 'Site URL', 'yaymatik' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn() => home_url(),
                'conditional' => false,
            ],
            'account_url'    => [
                'label'       => __( 'My account URL', 'yaymatik' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn() => self::page_url( 'myaccount' ),
                'conditional' => false,
            ],
            'shop_url'       => [
                'label'       => __( 'Shop URL', 'yaymatik' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn() => self::page_url( 'shop' ),
                'conditional' => false,
            ],
            'welcome_links'  => self::block( __( 'Account and shop buttons', 'yaymatik' ), fn( bool $html ) => EmailButton::pair( self::account_label(), self::page_url( 'myaccount' ), self::shop_label(), self::page_url( 'shop' ), $html ) ),
            'shop_button'    => self::block( __( 'Visit the shop button', 'yaymatik' ), fn( bool $html ) => EmailButton::render( self::shop_label(), self::page_url( 'shop' ), $html ) ),
            'account_button' => self::block( __( 'Go to your account button', 'yaymatik' ), fn( bool $html ) => EmailButton::render( self::account_label(), self::page_url( 'myaccount' ), $html ) ),
            'new_products'   => self::block( __( 'Newest products', 'yaymatik' ), fn( bool $html ) => ProductGrid::render( ProductGrid::newest(), $html ) ),
        ];
    }

    /**
     * An email block; a site has no object, so `$render` gets only the format.
     *
     * @param string   $label
     * @param callable $render fn( bool $html ): string.
     */
    private static function block( string $label, callable $render ): array {
        return [
            'label'       => $label,
            'type'        => self::TYPE_STRING,
            'value'       => fn() => $render( false ),
            'display'     => fn( $site, bool $html ) => $render( $html ),
            'conditional' => false,
            'block'       => true,
        ];
    }

    /**
     * @param string $page
     */
    private static function page_url( string $page ): string {
        return function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( $page ) : '';
    }

    private static function shop_label(): string {
        return __( 'Visit the shop', 'yaymatik' );
    }

    private static function account_label(): string {
        return __( 'Go to your account', 'yaymatik' );
    }
}
