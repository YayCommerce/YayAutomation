<?php
namespace YayMatik\Workflow\Modules;

use YayMatik\Workflow\Actions\AddOrderNote;
use YayMatik\Workflow\Actions\ChangeOrderStatus;
use YayMatik\Workflow\Actions\GenerateCoupon;
use YayMatik\Workflow\Actions\SendEmail;
use YayMatik\Workflow\Actions\SendInternalEmail;
use YayMatik\Workflow\DataItems\CartData;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\DataItems\OrderItemData;
use YayMatik\Workflow\DataItems\RefundData;
use YayMatik\Workflow\DataItems\ReviewData;
use YayMatik\Workflow\Triggers\CartAbandoned;
use YayMatik\Workflow\Triggers\CheckoutAbandoned;
use YayMatik\Workflow\Triggers\CustomerAccountCreated;
use YayMatik\Workflow\Triggers\OrderCreated;
use YayMatik\Workflow\Triggers\OrderNoteAdded;
use YayMatik\Workflow\Triggers\OrderPaid;
use YayMatik\Workflow\Triggers\OrderRefunded;
use YayMatik\Workflow\Triggers\OrderStatusChanged;
use YayMatik\Workflow\Triggers\ReviewPosted;

defined( 'ABSPATH' ) || exit;

/** Order, cart and customer triggers, the order, email and coupon actions, and order, cart and customer data. */
class WoocommerceModule extends Module {

    /** One colour, drawn as a CSS mask; inline, so it still loads when assets come from a CDN. */
    private const LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>';

    public const ID = 'woocommerce';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'WooCommerce', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'Orders, customers and store emails.', 'yaymatik-automation-for-woocommerce' );
    }

    public function logo(): string {
        return 'data:image/svg+xml,' . rawurlencode( self::LOGO );
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
            ReviewPosted::class,
        ];
    }

    public function actions(): array {
        return [
            SendEmail::class,
            SendInternalEmail::class,
            ChangeOrderStatus::class,
            AddOrderNote::class,
            GenerateCoupon::class,
        ];
    }

    public function data_items(): array {
        return [ OrderData::class, CartData::class, CustomerData::class, RefundData::class, ReviewData::class, OrderItemData::class ];
    }
}
