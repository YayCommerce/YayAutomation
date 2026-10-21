<?php
namespace YayMatik\Workflow\Modules;

use YayMatik\Workflow\Data\SiteData;
use YayMatik\Workflow\Steps\Conditions\Criteria;

defined( 'ABSPATH' ) || exit;

/** Steps built into Workflow Core itself (Condition) and the site data every workflow has. */
final class MatikModule implements ModuleInterface {

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
        // Three steps joined: one that starts, two it leads to.
        return self::SVG_OPEN . '<rect x="9" y="3" width="6" height="5" rx="1.5" /><circle cx="6.5" cy="18" r="2.5" /><circle cx="17.5" cy="18" r="2.5" /><path d="M12 8v3.5M6.5 15.5v-4h11v4" /></svg>';
    }

    public function active(): bool {
        return true;
    }

    public function tier(): string {
        return self::TIER_LITE;
    }

    public function enabled(): bool {
        return true;
    }

    public function steps(): array {
        return [ Criteria::class ];
    }

    public function data_items(): array {
        return [ new SiteData() ];
    }
}
