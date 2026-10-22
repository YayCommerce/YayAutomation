<?php
namespace YayMatik\Workflow\DataItems;

use YayMatik\Workflow\Core\Data\DataItem;

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
        return __( 'Your WordPress site: its name and address. Available in every workflow.', 'yaymatik' );
    }

    public function icon(): string {
        return 'globe';
    }

    protected function define(): array {
        return [
            'name' => [
                'label' => __( 'Site name', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn() => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            ],
            'url'  => [
                'label'       => __( 'Site URL', 'yaymatik' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn() => home_url(),
                'conditional' => false,
            ],
        ];
    }
}
