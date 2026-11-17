<?php
namespace YayMatik\Pro\Workflow\Modules;

use YayMatik\Pro\Workflow\Actions\WaitUntil;
use YayMatik\Workflow\Modules\YayMatikModule;

defined( 'ABSPATH' ) || exit;

/** YayMatik steps and data in YayMatik Pro: the free ones plus Pro's. */
class YayMatikProModule extends YayMatikModule {

    public function actions(): array {
        return array_merge( parent::actions(), [ WaitUntil::class ] );
    }
}
