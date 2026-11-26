<?php
namespace YayMatik\Workflow\Modules;

use YayMatik\Workflow\Actions\Wait;
use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\DataItems\SiteData;

defined( 'ABSPATH' ) || exit;

/** Workflow logic built into YayMatik itself, and the site data every workflow has. */
class YayMatikModule extends Module {

    /** One colour, drawn as a CSS mask; inline, so it still loads when assets come from a CDN. */
    private const LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="3" width="6" height="5" rx="1.5"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="18" r="2.5"/><path d="M12 8v3.5M6.5 15.5v-4h11v4"/></svg>';

    public const ID = 'yaymatik';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'YayMatik', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'Workflow logic: conditions and waits.', 'yaymatik-automation-for-woocommerce' );
    }

    public function logo(): string {
        return 'data:image/svg+xml,' . rawurlencode( self::LOGO );
    }

    public function conditions(): array {
        return [ Criteria::class ];
    }

    public function actions(): array {
        return [ Wait::class ];
    }

    public function data_items(): array {
        return [ SiteData::class ];
    }
}
