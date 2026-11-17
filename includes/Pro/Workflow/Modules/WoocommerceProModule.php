<?php
namespace YayMatik\Pro\Workflow\Modules;

use YayMatik\Pro\Workflow\Actions\ChangeCustomerRole;
use YayMatik\Pro\Workflow\Actions\ResendWcEmail;
use YayMatik\Pro\Workflow\Actions\UpdateCustomerMeta;
use YayMatik\Pro\Workflow\Actions\UpdateOrderMeta;
use YayMatik\Pro\Workflow\Triggers\CustomerInactive;
use YayMatik\Pro\Workflow\Triggers\CustomerOrderCountReaches;
use YayMatik\Pro\Workflow\Triggers\CustomerTotalSpendReaches;
use YayMatik\Workflow\Modules\WoocommerceModule;

defined( 'ABSPATH' ) || exit;

/** WooCommerce steps and data in YayMatik Pro: the free ones plus Pro's. */
class WoocommerceProModule extends WoocommerceModule {

    public function triggers(): array {
        return array_merge( parent::triggers(), [ CustomerInactive::class, CustomerOrderCountReaches::class, CustomerTotalSpendReaches::class ] );
    }

    public function actions(): array {
        return array_merge( parent::actions(), [ ResendWcEmail::class, UpdateOrderMeta::class, UpdateCustomerMeta::class, ChangeCustomerRole::class ] );
    }
}
