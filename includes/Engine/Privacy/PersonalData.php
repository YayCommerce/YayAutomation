<?php
namespace YayMatik\Engine\Privacy;

use YayMatik\Utils\Logger;
use YayMatik\Utils\SingletonTrait;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Settings;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;

defined( 'ABSPATH' ) || exit;

/** WordPress's personal data exporter and eraser for carts, the unsubscribe record and runs, and the policy text. */
class PersonalData {

    use SingletonTrait;

    private const KEY = 'yaymatik';

    private const PER_PAGE = 50;

    protected function __construct() {
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'exporters' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'erasers' ] );
        add_action( 'admin_init', [ $this, 'policy_text' ] );
    }

    /**
     * @param array $exporters
     */
    public function exporters( $exporters ): array {
        $exporters              = (array) $exporters;
        $exporters[ self::KEY ] = [
            'exporter_friendly_name' => __( 'YayMatik', 'yaymatik' ),
            'callback'               => [ $this, 'export' ],
        ];
        return $exporters;
    }

    /**
     * @param array $erasers
     */
    public function erasers( $erasers ): array {
        // First: WordPress's comments eraser blanks a review's email and user id, which is how this one finds review runs.
        return [
            self::KEY => [
                'eraser_friendly_name' => __( 'YayMatik', 'yaymatik' ),
                'callback'             => [ $this, 'erase' ],
            ],
        ] + (array) $erasers;
    }

    /**
     * Carts and the unsubscribe record on page 1; runs 50 per page.
     *
     * @param string $email
     * @param int    $page 1-based.
     */
    public function export( $email, $page = 1 ): array {
        $email = UnsubscribeLink::normalize( (string) $email );
        $page  = max( 1, (int) $page );
        if ( '' === $email ) {
            return [
                'data' => [],
                'done' => true,
            ];
        }
        $data = [];
        if ( 1 === $page ) {
            foreach ( CartStore::for_email( $email ) as $cart ) {
                $data[] = $this->cart_item( $cart );
            }
            $unsubscribe = UnsubscribeStore::find( $email );
            if ( $unsubscribe ) {
                $data[] = [
                    'group_id'    => 'yaymatik-unsubscribe',
                    'group_label' => __( 'Unsubscribe', 'yaymatik' ),
                    'item_id'     => 'yaymatik-unsubscribe',
                    'data'        => [
                        $this->value( __( 'Email', 'yaymatik' ), $unsubscribe['email'] ),
                        $this->value( __( 'Unsubscribed', 'yaymatik' ), get_date_from_gmt( $unsubscribe['created_at'] ) ),
                    ],
                ];
            }
            $milestones = TriggerClaimStore::find_keys( $email );
            if ( $milestones ) {
                $data[] = [
                    'group_id'    => 'yaymatik-milestones',
                    'group_label' => __( 'Customer milestones', 'yaymatik' ),
                    'item_id'     => 'yaymatik-milestones',
                    'data'        => [
                        $this->value( __( 'Workflows started for this email', 'yaymatik' ), (string) count( $milestones ) ),
                        $this->value( __( 'Last started', 'yaymatik' ), get_date_from_gmt( (string) end( $milestones )['claimed_at'] ) ),
                    ],
                ];
            }
        }
        $runs = RunStore::for_export( $email, $this->subjects( $email ), self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
        foreach ( $runs as $run ) {
            $data[] = $this->run_item( $run, $email );
        }
        return [
            'data' => $data,
            'done' => count( $runs ) < self::PER_PAGE,
        ];
    }

    /**
     * Stateless: every page re-queries the first 50 runs erase still changes; carts go on the last page.
     *
     * @param string $email
     * @param int    $page 1-based.
     */
    public function erase( $email, $page = 1 ): array {
        $email  = UnsubscribeLink::normalize( (string) $email );
        $result = [
            'items_removed'  => false,
            'items_retained' => false,
            'messages'       => [],
            'done'           => true,
        ];
        if ( '' === $email ) {
            return $result;
        }
        // WordPress merges the pages' results, so the unsubscribe message is added once.
        if ( 1 === max( 1, (int) $page ) && UnsubscribeStore::has( $email ) ) {
            $result['items_retained'] = true;
            $result['messages'][]     = __( 'The unsubscribe record is kept so this address gets no more workflow emails.', 'yaymatik' );
        }
        // Read on every page: the carts, whose ids are run subjects, stay until the last one.
        $subjects  = $this->subjects( $email );
        $runs      = RunStore::ids_mentioning( $email, $subjects, self::PER_PAGE );
        $ids       = array_keys( $runs );
        $waiting   = array_keys( $runs, RunStore::STATUS_WAITING, true );
        $cancelled = 0;
        // A waiting run would resume with `[deleted]` as its data, or still email the person.
        if ( $waiting ) {
            $reason    = __( "The customer's personal data was erased.", 'yaymatik' );
            $cancelled = RunStore::cancel_waiting( $waiting, $reason );
            if ( $cancelled ) {
                RunStepStore::cancel_waiting( $waiting, $reason );
            }
        }
        $changed = $cancelled + RunStore::anonymize( $ids, $email );
        if ( $ids && ! $changed ) {
            Logger::error( 'Erase found runs but changed none; stopped.', [ 'run_ids' => $ids ] );
            $result['items_retained'] = true;
            $result['messages'][]     = __( 'Some workflow runs could not be erased. See WooCommerce → Status → Logs (yaymatik).', 'yaymatik' );
        } else {
            $result['items_removed'] = $changed > 0;
            $result['done']          = count( $ids ) < self::PER_PAGE;
        }
        if ( ! $result['done'] ) {
            return $result;
        }
        // Carts stay while a run about them is in progress, so erasing again still finds it by its subject.
        if ( RunStore::in_progress_mentioning( $email, $subjects ) ) {
            $result['items_retained'] = true;
            $result['messages'][]     = __( 'A workflow run is in progress; erase again in a few minutes.', 'yaymatik' );
            return $result;
        }
        $result['items_removed'] = CartStore::delete_for_email( $email ) > 0 || $result['items_removed'];
        $claims                  = TriggerClaimStore::delete_keys( $email );
        if ( false === $claims ) {
            $result['items_retained'] = true;
            $result['messages'][]     = __( 'Some customer milestone records could not be erased. See WooCommerce → Status → Logs (yaymatik).', 'yaymatik' );
        }
        $result['items_removed'] = $claims > 0 || $result['items_removed'];
        return $result;
    }

    /**
     * Run subjects of the person's orders, carts, accounts and reviews: by billing email too, as a welcome run sends there.
     *
     * @param string $email Normalized.
     * @return string[]
     */
    private function subjects( string $email ): array {
        $orders   = wc_get_orders(
            [
                'customer' => $email,
                'return'   => 'ids',
                'limit'    => -1,
                'type'     => 'shop_order',
            ]
        );
        $subjects = array_map( fn( $id ) => 'order:' . (int) $id, (array) $orders );
        foreach ( CartStore::for_email( $email ) as $cart ) {
            $subjects[] = 'cart:' . (int) $cart['id'];
        }
        $users = get_users(
            [
                'meta_key'   => 'billing_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value' => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'fields'     => 'ID',
            ]
        );
        $user  = get_user_by( 'email', $email );
        if ( $user ) {
            $users[] = $user->ID;
        }
        foreach ( array_unique( array_map( 'intval', $users ) ) as $user_id ) {
            $subjects[] = 'user:' . $user_id;
        }
        // Reviews by the email typed, and by the accounts found above: a logged-in review stores the account's email at the time.
        $reviews = [ [ 'author_email' => $email ] ];
        foreach ( array_unique( array_map( 'intval', $users ) ) as $user_id ) {
            $reviews[] = [ 'user_id' => $user_id ];
        }
        foreach ( $reviews as $query ) {
            $ids = get_comments(
                $query + [
                    'type'   => 'review',
                    // 'all' leaves out spam and trash, which still hold the email.
                    'status' => [ 'approve', 'hold', 'spam', 'trash' ],
                    'fields' => 'ids',
                ]
            );
            foreach ( (array) $ids as $review_id ) {
                $subjects[] = 'review:' . (int) $review_id;
            }
        }
        return array_values( array_unique( $subjects ) );
    }

    public function policy_text(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }
        $paragraphs = [
            __( 'When you add products to your cart, we save the cart: its products, total and when it last changed. If you are logged in, we save your account email and first name with it. As a guest, the email address and first name you type at checkout are saved as you type them, before you place the order.', 'yaymatik' ),
            __( 'We use this to send you cart reminder emails. Each reminder holds a link that puts the saved products back in your cart.', 'yaymatik' ),
            sprintf(
                /* translators: 1: days a cart is kept, 2: days a workflow record is kept */
                __( 'A cart is deleted %1$d days after its last change. Records of the automated emails and other steps we ran for you are deleted %2$d days after they finish.', 'yaymatik' ),
                (int) Settings::get( 'cart_retention_days' ),
                (int) Settings::get( 'run_retention_days' )
            ),
            __( 'Some automated emails are sent once per customer, for example when your orders reach a number. To remember that one was sent to a guest, we keep a one-way hash of your email address, never the address itself.', 'yaymatik' ),
            __( 'When you ask us to erase your personal data, your carts and those hashes are deleted, and your email address is removed from those records; the records stay without it. Automated emails still scheduled for you are cancelled. If you unsubscribed from our emails, that record is kept so you get no more emails from us.', 'yaymatik' ),
        ];
        $html       = implode( '', array_map( fn( string $text ) => '<p>' . esc_html( $text ) . '</p>', $paragraphs ) );
        wp_add_privacy_policy_content( 'YayMatik', wp_kses_post( $html ) );
    }

    /**
     * @param array $cart A `carts` row.
     */
    private function cart_item( array $cart ): array {
        $lines    = json_decode( (string) $cart['items'], true );
        $products = array_map(
            fn( $line ) => sprintf( '%s × %d', (string) ( $line['name'] ?? '' ), (int) ( $line['quantity'] ?? 0 ) ),
            is_array( $lines ) ? $lines : []
        );
        return [
            'group_id'    => 'yaymatik-carts',
            'group_label' => __( 'Carts', 'yaymatik' ),
            'item_id'     => 'yaymatik-cart-' . (int) $cart['id'],
            'data'        => [
                $this->value( __( 'Products', 'yaymatik' ), implode( ', ', $products ) ),
                $this->value( __( 'Email', 'yaymatik' ), (string) $cart['email'] ),
                $this->value( __( 'First name', 'yaymatik' ), (string) $cart['first_name'] ),
                $this->value( __( 'Created', 'yaymatik' ), get_date_from_gmt( $cart['created_at'] ) ),
                $this->value( __( 'Last activity', 'yaymatik' ), get_date_from_gmt( $cart['last_activity_at'] ) ),
            ],
        ];
    }

    /**
     * @param array  $run `RunStore::for_export()` row.
     * @param string $email
     */
    private function run_item( array $run, string $email ): array {
        $data = [
            $this->value( __( 'Workflow', 'yaymatik' ), null === $run['workflow_name'] ? __( 'Deleted workflow', 'yaymatik' ) : (string) $run['workflow_name'] ),
            $this->value( __( 'Started', 'yaymatik' ), get_date_from_gmt( $run['started_at'] ) ),
            $this->value( __( 'Status', 'yaymatik' ), (string) $run['status'] ),
        ];
        // Only this address: a step may also have emailed other people.
        foreach ( RunStepStore::for_run( (int) $run['id'] ) as $step ) {
            $to = array_filter( (array) ( $step['output']['to'] ?? [] ), 'is_string' );
            if ( in_array( $email, array_map( [ UnsubscribeLink::class, 'normalize' ], $to ), true ) ) {
                $data[] = $this->value( __( 'Sent to', 'yaymatik' ), $email );
                break;
            }
        }
        return [
            'group_id'    => 'yaymatik-runs',
            'group_label' => __( 'Workflow runs', 'yaymatik' ),
            'item_id'     => 'yaymatik-run-' . (int) $run['id'],
            'data'        => $data,
        ];
    }

    /**
     * @param string $name
     * @param string $value
     */
    private function value( string $name, string $value ): array {
        return [
            'name'  => $name,
            'value' => $value,
        ];
    }
}
