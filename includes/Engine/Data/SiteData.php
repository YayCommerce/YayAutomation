<?php
namespace YayAutomation\Engine\Data;

defined( 'ABSPATH' ) || exit;

/** The WordPress site. Always available, whatever the trigger; it has no context object. */
final class SiteData extends DataItem {

    public const ID = 'site';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Site', 'yay-automation' );
    }

    public function description(): string {
        return __( 'Your WordPress site: its name and address. Available in every workflow.', 'yay-automation' );
    }

    protected function define(): array {
        return [
            'name' => [
                'label' => __( 'Site name', 'yay-automation' ),
                'type'  => self::TYPE_STRING,
                'value' => fn() => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            ],
            'url'  => [
                'label'       => __( 'Site URL', 'yay-automation' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn() => home_url(),
                'conditional' => false,
            ],
        ];
    }
}
