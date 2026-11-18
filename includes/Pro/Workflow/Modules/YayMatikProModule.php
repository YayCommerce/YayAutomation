<?php
namespace YayMatik\Pro\Workflow\Modules;

use YayMatik\Pro\Workflow\Actions\WaitUntil;
use YayMatik\Pro\Workflow\Conditions\CriteriaPro;
use YayMatik\Workflow\Modules\YayMatikModule;

defined( 'ABSPATH' ) || exit;

/** YayMatik steps and data in YayMatik Pro: the free ones plus Pro's. */
class YayMatikProModule extends YayMatikModule {

    public function conditions(): array {
        return [ CriteriaPro::class ];
    }

    public function actions(): array {
        return array_merge( parent::actions(), [ WaitUntil::class ] );
    }
}
