<?php
namespace YayMatik\Workflow\Modules;

use YayMatik\Workflow\Actions\AddOrderNote;
use YayMatik\Workflow\Actions\ChangeOrderStatus;
use YayMatik\Workflow\Actions\GenerateCoupon;
use YayMatik\Workflow\Actions\SendEmail;
use YayMatik\Workflow\Actions\SendInternalEmail;
use YayMatik\Workflow\Actions\UpdateOrderMeta;
use YayMatik\Workflow\Data\CustomerData;
use YayMatik\Workflow\Data\OrderData;
use YayMatik\Workflow\Data\OrderItemData;
use YayMatik\Workflow\Triggers\OrderCreated;
use YayMatik\Workflow\Triggers\OrderStatusChanged;

defined( 'ABSPATH' ) || exit;

/** Order triggers, the order, email and coupon actions, and order and customer data. */
class WoocommerceModule extends Module {

    public const ID = 'woocommerce';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'WooCommerce', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Orders, customers and store emails.', 'yaymatik' );
    }

    public function logo(): string {
        return YAYMATIK_PLUGIN_URL . 'assets/logos/woocommerce.svg';
    }

    public function active(): bool {
        return class_exists( 'WooCommerce' );
    }

    public function triggers(): array {
        return [ OrderCreated::class, OrderStatusChanged::class ];
    }

    public function actions(): array {
        return [
            SendEmail::class,
            SendInternalEmail::class,
            ChangeOrderStatus::class,
            AddOrderNote::class,
            UpdateOrderMeta::class,
            GenerateCoupon::class,
        ];
    }

    public function data_items(): array {
        return [ OrderData::class, CustomerData::class, OrderItemData::class ];
    }
}
