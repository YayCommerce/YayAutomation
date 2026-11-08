<?php
namespace YayMatik\Workflow\Modules;

use YayMatik\Workflow\Actions\AddOrderNote;
use YayMatik\Workflow\Actions\ChangeCustomerRole;
use YayMatik\Workflow\Actions\ChangeOrderStatus;
use YayMatik\Workflow\Actions\GenerateCoupon;
use YayMatik\Workflow\Actions\ResendWcEmail;
use YayMatik\Workflow\Actions\SendEmail;
use YayMatik\Workflow\Actions\SendInternalEmail;
use YayMatik\Workflow\Actions\UpdateCustomerMeta;
use YayMatik\Workflow\Actions\UpdateOrderMeta;
use YayMatik\Workflow\DataItems\CartData;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\DataItems\OrderItemData;
use YayMatik\Workflow\DataItems\RefundData;
use YayMatik\Workflow\DataItems\ReviewData;
use YayMatik\Workflow\Triggers\CartAbandoned;
use YayMatik\Workflow\Triggers\CheckoutAbandoned;
use YayMatik\Workflow\Triggers\CustomerAccountCreated;
use YayMatik\Workflow\Triggers\CustomerInactive;
use YayMatik\Workflow\Triggers\CustomerOrderCountReaches;
use YayMatik\Workflow\Triggers\CustomerTotalSpendReaches;
use YayMatik\Workflow\Triggers\OrderCreated;
use YayMatik\Workflow\Triggers\OrderNoteAdded;
use YayMatik\Workflow\Triggers\OrderPaid;
use YayMatik\Workflow\Triggers\OrderRefunded;
use YayMatik\Workflow\Triggers\OrderStatusChanged;
use YayMatik\Workflow\Triggers\ReviewPosted;

defined( 'ABSPATH' ) || exit;

/** Order, cart and customer triggers, the order, email and coupon actions, and order, cart and customer data. */
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
        return [
            OrderCreated::class,
            OrderStatusChanged::class,
            OrderPaid::class,
            OrderRefunded::class,
            OrderNoteAdded::class,
            CartAbandoned::class,
            CheckoutAbandoned::class,
            CustomerAccountCreated::class,
            CustomerInactive::class,
            CustomerOrderCountReaches::class,
            CustomerTotalSpendReaches::class,
            ReviewPosted::class,
        ];
    }

    public function actions(): array {
        return [
            SendEmail::class,
            SendInternalEmail::class,
            ResendWcEmail::class,
            ChangeOrderStatus::class,
            AddOrderNote::class,
            UpdateOrderMeta::class,
            UpdateCustomerMeta::class,
            ChangeCustomerRole::class,
            GenerateCoupon::class,
        ];
    }

    public function data_items(): array {
        return [ OrderData::class, CartData::class, CustomerData::class, RefundData::class, ReviewData::class, OrderItemData::class ];
    }
}
