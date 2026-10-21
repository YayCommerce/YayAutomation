<?php
namespace YayMatik\Workflow\Modules;

use YayMatik\Workflow\Data\CustomerData;
use YayMatik\Workflow\Data\OrderData;
use YayMatik\Workflow\Steps\Actions\AddOrderNote;
use YayMatik\Workflow\Steps\Actions\ChangeOrderStatus;
use YayMatik\Workflow\Steps\Actions\GenerateCoupon;
use YayMatik\Workflow\Steps\Actions\SendEmail;
use YayMatik\Workflow\Steps\Actions\SendInternalEmail;
use YayMatik\Workflow\Steps\Actions\UpdateOrderMeta;
use YayMatik\Workflow\Steps\Triggers\OrderCreated;
use YayMatik\Workflow\Steps\Triggers\OrderStatusChanged;

defined( 'ABSPATH' ) || exit;

/** Core's WooCommerce part: the order triggers, the order, email and coupon actions, orders and customers. */
final class WoocommerceModule implements ModuleInterface {

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
        // A shopping cart outline (lucide `shopping-cart`): a store, not WooCommerce's own mark.
        return self::SVG_OPEN . '<circle cx="8" cy="21" r="1" /><circle cx="19" cy="21" r="1" /><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12" /></svg>';
    }

    public function active(): bool {
        return class_exists( 'WooCommerce' );
    }

    public function tier(): string {
        return self::TIER_LITE;
    }

    public function enabled(): bool {
        return true;
    }

    public function steps(): array {
        return [
            OrderCreated::class,
            OrderStatusChanged::class,
            SendEmail::class,
            SendInternalEmail::class,
            ChangeOrderStatus::class,
            AddOrderNote::class,
            UpdateOrderMeta::class,
            GenerateCoupon::class,
        ];
    }

    public function data_items(): array {
        return [ new OrderData(), new CustomerData() ];
    }
}
