<?php
namespace YayMatik\Workflow\Modules;

use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Data\SiteData;

defined( 'ABSPATH' ) || exit;

/** Workflow logic built into YayMatik itself, and the site data every workflow has. */
class YayMatikModule extends Module {

    public const ID = 'yaymatik';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'YayMatik', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Workflow logic: conditions and waits.', 'yaymatik' );
    }

    public function logo(): string {
        return YAYMATIK_PLUGIN_URL . 'assets/logos/yaymatik.svg';
    }

    public function conditions(): array {
        return [ Criteria::class ];
    }

    public function data_items(): array {
        return [ SiteData::class ];
    }
}
